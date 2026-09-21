<?php

/**
 * Copyright 2019 Colopl Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Colopl\Spanner\Tests\Queue;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Queue\SpannerJob;
use Colopl\Spanner\Queue\SpannerQueue;
use Colopl\Spanner\Schema\Blueprint;
use Colopl\Spanner\Tests\TestCase;
use Illuminate\Queue\QueueManager;
use RuntimeException;
use Throwable;

/**
 * End to end coverage against the configured Cloud Spanner database.
 *
 * Cloud Spanner queues require the Enterprise or Enterprise Plus edition and
 * are not implemented by the Spanner emulator, so every test here is skipped
 * unless `create queue` actually succeeds. Run the suite against a real
 * instance to exercise them.
 *
 * @see https://cloud.google.com/spanner/docs/queues/queues-overview
 */
class SpannerQueueIntegrationTest extends TestCase
{
    protected string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueName = $this->generateTableName('QueueTest');
        $connection = $this->getDefaultConnection();

        try {
            $connection->getSchemaBuilder()->createQueue(
                $this->queueName,
                static function (Blueprint $queue) {
                    $queue->string(SpannerQueue::MESSAGE_ID_COLUMN, 36)->primary();
                    $queue->string(SpannerQueue::PAYLOAD_COLUMN, 'max');
                },
            );
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'Cloud Spanner queues are unavailable on this backend: ' . $e->getMessage(),
            );
        }

        $this->beforeApplicationDestroyed(function () use ($connection) {
            $connection->getSchemaBuilder()->dropQueueIfExists($this->queueName);
        });

        config()->set('queue.connections.spanner', [
            'driver' => 'spanner',
            'connection' => 'main',
            'queue' => $this->queueName,
            'retry_after' => 60,
            'block_for' => 2,
        ]);
    }

    protected function queue(): SpannerQueue
    {
        /** @var QueueManager $manager */
        $manager = $this->app->make('queue');
        $queue = $manager->connection('spanner');

        return $queue instanceof SpannerQueue
            ? $queue
            : throw new RuntimeException('Expected the spanner queue driver.');
    }

    public function test_a_pushed_job_can_be_popped_and_acknowledged(): void
    {
        $queue = $this->queue();
        $payload = json_encode(['job' => 'Foo', 'data' => ['a' => 1]]);
        assert(is_string($payload));

        $queue->pushRaw($payload);

        $this->assertSame(1, $queue->size());
        $this->assertSame(1, $queue->pendingSize());

        $job = $queue->pop();

        $this->assertInstanceOf(SpannerJob::class, $job);
        $this->assertSame($this->queueName, $job->getQueue());
        $this->assertSame(1, $job->attempts());
        $this->assertSame('Foo', $job->payload()['job']);

        // Reserving keeps exactly one message around, invisible until it expires.
        $this->assertSame(1, $queue->size());
        $this->assertSame(0, $queue->pendingSize());
        $this->assertSame(1, $queue->delayedSize());

        $job->delete();

        $this->assertSame(0, $queue->size());
    }

    public function test_a_released_job_is_delivered_again_with_a_higher_attempt_count(): void
    {
        $queue = $this->queue();
        $payload = json_encode(['job' => 'Foo']);
        assert(is_string($payload));

        $queue->pushRaw($payload);

        $first = $queue->pop();
        $this->assertInstanceOf(SpannerJob::class, $first);
        $this->assertSame(1, $first->attempts());
        $first->release();

        $second = $queue->pop();
        $this->assertInstanceOf(SpannerJob::class, $second);
        $this->assertSame(2, $second->attempts());
        $second->delete();

        $this->assertSame(0, $queue->size());
    }

    public function test_a_delayed_job_is_not_deliverable_yet(): void
    {
        $queue = $this->queue();
        $payload = json_encode(['job' => 'Foo']);
        assert(is_string($payload));

        $queue->later(3600, 'Foo');

        $this->assertSame(1, $queue->size());
        $this->assertSame(0, $queue->pendingSize());
        $this->assertSame(1, $queue->delayedSize());
        $this->assertNull($queue->pop());
    }

    public function test_a_job_pushed_in_a_rolled_back_transaction_is_never_delivered(): void
    {
        $queue = $this->queue();
        $connection = $this->getDefaultConnection();
        assert($connection instanceof Connection);

        try {
            $connection->transaction(function () use ($queue): void {
                $queue->pushRaw('{"job":"Foo"}');
                throw new RuntimeException('rollback');
            });
            $this->fail('Expected the transaction to be rolled back.');
        } catch (RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }

        $this->assertSame(0, $queue->size());
        $this->assertNull($queue->pop());
    }

    public function test_an_interleaved_queue_keeps_a_job_under_its_parent_row(): void
    {
        $connection = $this->getDefaultConnection();
        $interleaved = $this->generateTableName('QueueInterleavedTest');
        $userId = $this->generateUuid();

        $connection->getSchemaBuilder()->createQueue(
            $interleaved,
            static function (Blueprint $queue) {
                // Must positionally match the parent's primary key, in name and type.
                $queue->string('userId', 36);
                $queue->string(SpannerQueue::MESSAGE_ID_COLUMN, 36);
                $queue->string(SpannerQueue::PAYLOAD_COLUMN, 'max');
                $queue->primary(['userId', SpannerQueue::MESSAGE_ID_COLUMN]);
                $queue->interleaveInParent(self::TABLE_NAME_USER)->cascadeOnDelete();
            },
        );
        $this->beforeApplicationDestroyed(function () use ($connection, $interleaved) {
            $connection->getSchemaBuilder()->dropQueueIfExists($interleaved);
        });

        $connection->table(self::TABLE_NAME_USER)->insert(['userId' => $userId, 'name' => 'test']);

        config()->set('queue.connections.spanner-interleaved', [
            'driver' => 'spanner',
            'connection' => 'main',
            'queue' => $interleaved,
            'block_for' => 2,
            'interleave_keys' => ['userId'],
        ]);

        /** @var QueueManager $manager */
        $manager = $this->app->make('queue');
        $queue = $manager->connection('spanner-interleaved');
        assert($queue instanceof SpannerQueue);

        // `userId` is discovered from the payload's data.
        $queue->push('Foo', ['userId' => $userId]);

        $job = $queue->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);
        // Reservation must not move the message to a different parent row.
        $this->assertSame($userId, $job->getKey()['userId']);

        $job->delete();
        $this->assertSame(0, $queue->size());
    }

    public function test_clear_removes_every_message(): void
    {
        $queue = $this->queue();

        $queue->pushRaw('{"job":"A"}');
        $queue->pushRaw('{"job":"B"}');
        $this->assertSame(2, $queue->size());

        $this->assertSame(2, $queue->clear($this->queueName));
        $this->assertSame(0, $queue->size());
    }
}
