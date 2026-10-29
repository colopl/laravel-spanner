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
use Colopl\Spanner\Schema\QueueDefinition;
use Colopl\Spanner\Tests\Support\RecordingLogger;
use Colopl\Spanner\Tests\TestCase;
use Closure;
use Google\Cloud\Spanner\Timestamp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * End to end coverage against the configured Cloud Spanner database.
 *
 * Cloud Spanner queues require the Enterprise or Enterprise Plus edition, and
 * only recent builds of the Spanner emulator implement them, so every test here
 * is skipped unless `create queue` actually succeeds.
 *
 * Mind the differences between the emulator and Cloud Spanner when writing
 * assertions. The emulator releases a stream's rows when its `max_duration`
 * ends instead of pushing them live, so a `pop()` always lasts the whole
 * `block_for`, and it hands one message to several concurrent receivers. Tests
 * that wait for a message to turn up therefore poll, like a worker does, rather
 * than relying on a particular `pop()` returning it.
 *
 * @see https://cloud.google.com/spanner/docs/queues/queues-overview
 */
class SpannerQueueIntegrationTest extends TestCase
{
    protected string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        QueueIntegrationJob::$handled = 0;
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
            'block_for' => 1,
            'interleave_keys' => [$this->queueName => []],
        ]);
    }

    /**
     * Polls the way a worker does, until a job arrives or the time is up.
     */
    protected function popWithin(SpannerQueue $queue, int $seconds): ?SpannerJob
    {
        $deadline = microtime(true) + $seconds;

        do {
            $job = $queue->pop();
            if ($job instanceof SpannerJob) {
                return $job;
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    protected function worker(): Worker
    {
        $worker = $this->app->make('queue.worker');
        assert($worker instanceof Worker);

        return $worker;
    }

    /**
     * Runs the worker until the condition holds or the time is up.
     *
     * @param Closure(): bool $done
     */
    protected function workUntil(WorkerOptions $options, Closure $done, int $seconds = 15): void
    {
        $worker = $this->worker();
        $deadline = microtime(true) + $seconds;

        while (!$done() && microtime(true) < $deadline) {
            $worker->runNextJob('spanner', $this->queueName, $options);
        }
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
            'block_for' => 1,
            'interleave_keys' => [$interleaved => ['userId']],
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

    /**
     * Creates a queue interleaved in the User table, dropped when the test ends.
     */
    protected function createInterleavedQueue(string $name): void
    {
        $connection = $this->getDefaultConnection();

        $connection->getSchemaBuilder()->createQueue(
            $name,
            static function (Blueprint $queue) {
                $queue->string('userId', 36);
                $queue->string(SpannerQueue::MESSAGE_ID_COLUMN, 36);
                $queue->string(SpannerQueue::PAYLOAD_COLUMN, 'max');
                $queue->primary(['userId', SpannerQueue::MESSAGE_ID_COLUMN]);
                $queue->interleaveInParent(self::TABLE_NAME_USER)->cascadeOnDelete();
            },
        );
        $this->beforeApplicationDestroyed(function () use ($connection, $name) {
            $connection->getSchemaBuilder()->dropQueueIfExists($name);
        });
    }

    public function test_queues_of_one_connection_can_have_different_interleave_keys(): void
    {
        $connection = $this->getDefaultConnection();
        $interleaved = $this->generateTableName('QueueInterleavedTest');
        $userId = $this->generateUuid();
        $this->createInterleavedQueue($interleaved);
        $connection->table(self::TABLE_NAME_USER)->insert(['userId' => $userId, 'name' => 'test']);

        config()->set('queue.connections.spanner.interleave_keys', [
            $this->queueName => [],
            $interleaved => ['userId'],
        ]);
        $queue = $this->queue();

        $queue->push('Foo', ['userId' => $userId], $interleaved);
        $queue->pushRaw('{"job":"Bar"}', $this->queueName);

        $this->assertSame(1, $queue->size($interleaved));
        $this->assertSame(1, $queue->size($this->queueName));

        $interleavedJob = $queue->pop($interleaved);
        $this->assertInstanceOf(SpannerJob::class, $interleavedJob);
        $this->assertSame($userId, $interleavedJob->getKey()['userId']);
        $interleavedJob->delete();

        $plainJob = $queue->pop($this->queueName);
        $this->assertInstanceOf(SpannerJob::class, $plainJob);
        $this->assertSame([SpannerQueue::MESSAGE_ID_COLUMN], array_keys($plainJob->getKey()));
        $plainJob->delete();
    }

    public function test_an_unlisted_queue_is_a_configuration_error(): void
    {
        config()->set('queue.connections.spanner.interleave_keys', []);
        $queue = $this->queue();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('no interleave_keys entry for queue "%s"', $this->queueName));

        $queue->pushRaw('{}');
    }

    public function test_an_unacknowledged_job_is_delivered_again_after_retry_after(): void
    {
        config()->set('queue.connections.spanner.retry_after', 2);
        $queue = $this->queue();
        $queue->pushRaw((string) json_encode(['job' => 'Foo']));

        $first = $this->popWithin($queue, 10);
        $this->assertInstanceOf(SpannerJob::class, $first);
        $this->assertSame(1, $first->attempts());

        // The worker dies here: the job is neither deleted nor released. The
        // reserved message simply becomes deliverable once `retry_after` is up.
        $second = $this->popWithin($queue, 15);
        $this->assertInstanceOf(SpannerJob::class, $second);
        $this->assertSame(2, $second->attempts());
        $this->assertSame('Foo', $second->payload()['job']);
        // Every reservation is a message of its own.
        $this->assertNotSame($first->getJobId(), $second->getJobId());

        $second->delete();
        $this->assertSame(0, $queue->size());
    }

    public function test_a_job_is_reserved_for_longer_than_its_timeout(): void
    {
        config()->set('queue.connections.spanner.retry_after', 5);
        $queue = $this->queue();
        $queue->pushRaw((string) json_encode(['job' => 'Foo', 'timeout' => 300]));

        $job = $this->popWithin($queue, 10);
        $this->assertInstanceOf(SpannerJob::class, $job);

        $row = $this->getDefaultConnection()->selectOne(sprintf(
            'select DeliverTime from `%s` where MessageId = ?',
            $this->queueName,
        ), [$job->getJobId()]);
        $this->assertIsArray($row);
        $deliverTime = $row['DeliverTime'];
        $this->assertInstanceOf(Timestamp::class, $deliverTime);
        // The timeout and its margin, not the shorter retry_after.
        $this->assertGreaterThan(
            time() + 300,
            $deliverTime->get()->getTimestamp(),
        );

        $job->delete();
    }

    public function test_acknowledging_a_message_twice_reports_the_second_as_already_taken(): void
    {
        $queue = $this->queue();
        $key = [SpannerQueue::MESSAGE_ID_COLUMN => $queue->pushRaw('{"job":"Foo"}')];

        // `reserve()` tells that another worker took a message by this count.
        $this->assertSame(1, $queue->acknowledge($this->queueName, $key));
        $this->assertSame(0, $queue->acknowledge($this->queueName, $key));
    }

    public function test_deleting_a_job_that_another_worker_already_processed_logs_a_notice(): void
    {
        $logger = new RecordingLogger();
        $this->app->instance('log', $logger);
        $queue = $this->queue();
        $queue->pushRaw((string) json_encode(['job' => 'Foo']));

        $job = $this->popWithin($queue, 10);
        $this->assertInstanceOf(SpannerJob::class, $job);

        // Another worker handled the job once its reservation ran out.
        $this->assertSame(1, $queue->acknowledge($this->queueName, $job->getKey()));
        $job->delete();

        $notices = array_values(array_filter($logger->records, static fn(array $r): bool => $r['level'] === 'notice'));
        $this->assertCount(1, $notices);
        $this->assertStringContainsString($job->getJobId(), $notices[0]['message']);
    }

    public function test_a_queue_can_expire_stale_messages_with_a_row_deletion_policy(): void
    {
        $connection = $this->getDefaultConnection();
        $expiring = $this->generateTableName('QueueTtlTest');

        // A row deletion policy can only use a column that the queue declares
        // itself, so `DeliverTime` has to be declared, as part of the key.
        $connection->getSchemaBuilder()->createQueue(
            $expiring,
            static function (Blueprint $queue) {
                $queue->string(SpannerQueue::MESSAGE_ID_COLUMN, 36);
                $queue->timestamp(SpannerQueue::DELIVER_TIME_COLUMN);
                $queue->string(SpannerQueue::PAYLOAD_COLUMN, 'max');
                $queue->primary([SpannerQueue::MESSAGE_ID_COLUMN, SpannerQueue::DELIVER_TIME_COLUMN]);
                $queue->deleteRowsOlderThan(SpannerQueue::DELIVER_TIME_COLUMN, 7);
            },
        );
        $this->beforeApplicationDestroyed(function () use ($connection, $expiring) {
            $connection->getSchemaBuilder()->dropQueueIfExists($expiring);
        });

        config()->set('queue.connections.spanner-expiring', [
            'driver' => 'spanner',
            'connection' => 'main',
            'queue' => $expiring,
            'block_for' => 1,
            'interleave_keys' => [$expiring => []],
        ]);

        /** @var QueueManager $manager */
        $manager = $this->app->make('queue');
        $queue = $manager->connection('spanner-expiring');
        assert($queue instanceof SpannerQueue);

        $queue->pushRaw('{"job":"Foo"}');

        $job = $this->popWithin($queue, 10);
        $this->assertInstanceOf(SpannerJob::class, $job);
        $job->delete();
        $this->assertSame(0, $queue->size());
    }

    public function test_a_laravel_worker_runs_a_dispatched_job(): void
    {
        $queue = $this->queue();
        $queue->push(new QueueIntegrationJob());

        $this->workUntil(
            new WorkerOptions(sleep: 0, maxTries: 1),
            static fn() => QueueIntegrationJob::$handled >= 1,
        );

        $this->assertSame(1, QueueIntegrationJob::$handled);
        $this->assertSame(0, $queue->size());
    }

    public function test_a_laravel_worker_retries_a_failing_job_and_then_fails_it(): void
    {
        $queue = $this->queue();
        $failures = [];
        $this->app['events']->listen(JobFailed::class, static function (JobFailed $event) use (&$failures) {
            $failures[] = $event->exception->getMessage();
        });

        $queue->push(new QueueIntegrationJob(fail: true));
        $options = new WorkerOptions(sleep: 0, maxTries: 2);

        // The first attempt fails and the job is released back to the queue.
        $this->workUntil($options, static fn() => QueueIntegrationJob::$handled >= 1);
        $this->assertSame(1, QueueIntegrationJob::$handled);
        $this->assertSame([], $failures);
        $this->assertSame(1, $queue->size());

        // The second attempt reaches `maxTries`, so the job fails for good.
        $this->workUntil($options, static fn() => QueueIntegrationJob::$handled >= 2);
        $this->assertSame(2, QueueIntegrationJob::$handled);
        $this->assertSame(['boom'], $failures);
        $this->assertSame(0, $queue->size());
    }

    public function test_the_ddl_generated_by_the_schema_builder_is_accepted(): void
    {
        $connection = $this->getDefaultConnection();
        $connection->useDefaultSchemaGrammar();
        $schema = $connection->getSchemaBuilder();
        $columns = static function (Blueprint $queue) {
            $queue->string(SpannerQueue::MESSAGE_ID_COLUMN, 36)->primary();
            $queue->string(SpannerQueue::PAYLOAD_COLUMN, 'max');
        };

        $name = $this->generateTableName('QueueDdlTest');
        $this->beforeApplicationDestroyed(function () use ($schema, $name) {
            $schema->dropQueueIfExists($name);
            $schema->dropQueueIfExists($name . '_options');
        });

        // The unit tests only compare SQL text. These have to get past the parser.
        $schema->createQueueIfNotExists($name, $columns);
        $schema->createQueueIfNotExists($name, $columns);
        $this->assertTrue($schema->hasQueue($name));

        // Create time options are set on the definition that the callback receives.
        $schema->createQueue(
            $name . '_options',
            static function (Blueprint $queue, QueueDefinition $definition) use ($columns) {
                $definition->disableSend(false)->disableDelivery(false);
                $columns($queue);
            },
        );
        $this->assertTrue($schema->hasQueue($name . '_options'));

        $schema->table($name, static fn(Blueprint $queue) => $queue->alterQueue()->disableDelivery(true)->disableSend(false));
        $schema->table($name, static fn(Blueprint $queue) => $queue->alterQueue()->disableDelivery(null)->disableSend(null));

        $schema->dropQueue($name);
        $schema->dropQueueIfExists($name);
        $this->assertFalse($schema->hasQueue($name));
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

class QueueIntegrationJob implements ShouldQueue
{
    use Queueable;

    /**
     * How many times a worker ran a job.
     */
    public static int $handled = 0;

    public function __construct(
        public bool $fail = false,
    ) {
    }

    public function handle(): void
    {
        static::$handled++;

        if ($this->fail) {
            throw new RuntimeException('boom');
        }
    }
}
