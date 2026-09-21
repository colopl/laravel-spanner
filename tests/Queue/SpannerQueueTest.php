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

use Colopl\Spanner\Queue\SpannerJob;
use Colopl\Spanner\Queue\SpannerQueue;
use Colopl\Spanner\Tests\Support\RecordingConnection;
use Colopl\Spanner\Tests\TestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Spanner\Timestamp;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

class SpannerQueueTest extends TestCase
{
    protected const QUEUE_NAME = 'Jobs';

    protected function tearDown(): void
    {
        SpannerQueue::resolveKeysUsing(null);

        parent::tearDown();
    }

    /**
     * @param list<string> $keyColumns
     */
    protected function createQueue(
        RecordingConnection $connection,
        int $retryAfter = 60,
        int $blockFor = 20,
        array $keyColumns = [],
    ): SpannerQueue {
        $queue = new SpannerQueue(
            $connection,
            self::QUEUE_NAME,
            $retryAfter,
            $blockFor,
            false,
            $keyColumns,
        );
        $queue->setContainer(Container::getInstance());
        $queue->setConnectionName('spanner');

        return $queue;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<array-key, mixed>
     */
    protected function message(array $overrides = []): array
    {
        return $overrides + [
            SpannerQueue::MESSAGE_ID_COLUMN => 'message-1',
            SpannerQueue::PAYLOAD_COLUMN => json_encode(['uuid' => 'job-uuid', 'attempts' => 0]),
            SpannerQueue::LEASE_EXPIRATION_COLUMN => new Timestamp(new DateTimeImmutable('+10 seconds')),
        ];
    }

    public function test_pushRaw_sends_a_message_deliverable_now(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-18 12:00:00'));

        $conn = new RecordingConnection();
        $id = $this->createQueue($conn)->pushRaw('{"job":"Foo"}');

        $recorded = $conn->recordedAt(0);
        $this->assertSame(
            'insert into `Jobs` (`MessageId`, `Payload`, `DeliverTime`) values (?, ?, ?)',
            $recorded['sql'],
        );
        $this->assertSame($id, $recorded['bindings'][0]);
        $this->assertSame('{"job":"Foo"}', $recorded['bindings'][1]);
        $this->assertInstanceOf(DateTimeInterface::class, $recorded['bindings'][2]);
        $this->assertSame($now->getTimestamp(), $recorded['bindings'][2]->getTimestamp());

        Carbon::setTestNow();
    }

    public function test_pushRaw_applies_the_table_prefix(): void
    {
        $conn = new RecordingConnection('app_');
        $this->createQueue($conn)->pushRaw('{}');

        $this->assertStringStartsWith('insert into `app_Jobs`', $conn->recordedAt(0)['sql']);
    }

    public function test_later_schedules_delivery_into_the_future(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-18 12:00:00'));

        $conn = new RecordingConnection();
        $this->createQueue($conn)->later(90, 'JobClass', ['a' => 1]);

        $deliverTime = $conn->recordedAt(0)['bindings'][2];
        $this->assertInstanceOf(DateTimeInterface::class, $deliverTime);
        $this->assertSame($now->getTimestamp() + 90, $deliverTime->getTimestamp());

        Carbon::setTestNow();
    }

    public function test_push_stores_a_payload_the_worker_can_decode(): void
    {
        $conn = new RecordingConnection();
        $this->createQueue($conn)->push('JobClass', ['a' => 1]);

        $payload = json_decode($conn->recordedAt(0)['bindings'][1], true);
        $this->assertSame('JobClass', $payload['job']);
        $this->assertSame(['a' => 1], $payload['data']);
        // Laravel does not track attempts in the payload; the driver adds the
        // counter itself when the message is first handed to a worker.
        $this->assertArrayNotHasKey('attempts', $payload);
    }

    public function test_first_delivery_of_a_pushed_job_counts_as_one_attempt(): void
    {
        $conn = new RecordingConnection();
        $this->createQueue($conn)->push('JobClass');

        $conn->messages = [$this->message([
            SpannerQueue::PAYLOAD_COLUMN => $conn->recordedAt(0)['bindings'][1],
        ])];

        $job = $this->createQueue($conn)->pop();

        $this->assertInstanceOf(SpannerJob::class, $job);
        $this->assertSame(1, $job->attempts());
    }

    public function test_bulk_sends_every_job_in_one_transaction(): void
    {
        $conn = new RecordingConnection();
        $this->createQueue($conn)->bulk(['JobA', 'JobB', 'JobC']);

        $this->assertSame(1, $conn->transactionCount);
        $this->assertCount(3, $conn->recorded);
    }

    public function test_pop_receives_through_the_queues_own_tvf(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message()];

        $this->createQueue($conn, blockFor: 30)->pop();

        $receive = $conn->recordedAt(0);
        $this->assertSame(
            'select `MessageId`, `Payload`, `SpannerLeaseExpirationTimestamp` ' .
            "from RECEIVE_Jobs(max_duration => '30s')",
            $receive['sql'],
        );
        // A strong read is required, so the query must not join a transaction.
        $this->assertTrue($receive['options']['singleUse']);
        // The RPC deadline has to outlive max_duration.
        $this->assertSame(40000, $receive['options']['timeoutMillis']);
    }

    public function test_pop_reserves_by_acknowledging_and_rescheduling(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-18 12:00:00'));

        $conn = new RecordingConnection();
        $conn->messages = [$this->message()];

        $job = $this->createQueue($conn, retryAfter: 120)->pop();

        $this->assertInstanceOf(SpannerJob::class, $job);
        $this->assertSame(1, $conn->transactionCount);

        $ack = $conn->recordedAt(1);
        $this->assertSame('delete from `Jobs` where `MessageId` = ?', $ack['sql']);
        $this->assertSame(['message-1'], $ack['bindings']);

        $resend = $conn->recordedAt(2);
        $this->assertStringStartsWith('insert into `Jobs`', $resend['sql']);
        $this->assertSame($now->getTimestamp() + 120, $resend['bindings'][2]->getTimestamp());

        // The reserved message is the one the job now refers to.
        $this->assertSame($resend['bindings'][0], $job->getJobId());
        $this->assertNotSame('message-1', $job->getJobId());

        Carbon::setTestNow();
    }

    public function test_pop_counts_every_delivery_as_an_attempt(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message([
            SpannerQueue::PAYLOAD_COLUMN => json_encode(['uuid' => 'job-uuid', 'attempts' => 2]),
        ])];

        $job = $this->createQueue($conn)->pop();

        $this->assertInstanceOf(SpannerJob::class, $job);
        $this->assertSame(3, $job->attempts());
        // The incremented payload is what gets rescheduled.
        $this->assertSame(3, json_decode($conn->recordedAt(2)['bindings'][1], true)['attempts']);
    }

    public function test_pop_returns_null_once_the_stream_ends(): void
    {
        $conn = new RecordingConnection();

        $this->assertNull($this->createQueue($conn)->pop());
        $this->assertSame(1, $conn->streamsOpened);
    }

    public function test_pop_keeps_one_stream_open_across_calls(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-1']),
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-2']),
        ];

        $queue = $this->createQueue($conn);
        $first = $queue->pop();
        $second = $queue->pop();

        $this->assertInstanceOf(SpannerJob::class, $first);
        $this->assertInstanceOf(SpannerJob::class, $second);
        // Spanner expects one long running receiver per worker, per queue.
        $this->assertSame(1, $conn->streamsOpened);
        $this->assertSame('delete from `Jobs` where `MessageId` = ?', $conn->recordedAt(3)['sql']);
        $this->assertSame(['message-2'], $conn->recordedAt(3)['bindings']);
    }

    public function test_pop_skips_a_message_another_worker_already_took(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'taken']),
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'mine']),
        ];
        // The acknowledgement of the first message deletes nothing.
        $conn->affectedRows = [1 => 0];

        $job = $this->createQueue($conn)->pop();

        $this->assertInstanceOf(SpannerJob::class, $job);
        // No message was rescheduled for the one that was already taken.
        $this->assertSame([
            'delete from `Jobs` where `MessageId` = ?',
            'delete from `Jobs` where `MessageId` = ?',
            'insert into `Jobs` (`MessageId`, `Payload`, `DeliverTime`) values (?, ?, ?)',
        ], array_slice($conn->recordedSql(), 1));
        $this->assertSame(['mine'], $conn->recordedAt(2)['bindings']);
    }

    public function test_pop_skips_a_message_whose_lease_already_expired(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [
            $this->message([
                SpannerQueue::MESSAGE_ID_COLUMN => 'expired',
                SpannerQueue::LEASE_EXPIRATION_COLUMN => new Timestamp(new DateTimeImmutable('-1 second')),
            ]),
        ];

        $this->assertNull($this->createQueue($conn)->pop());
        // Only the receiving query ran; the message was left for redelivery.
        $this->assertCount(1, $conn->recorded);
    }

    public function test_pop_rejects_receiving_inside_a_transaction(): void
    {
        $conn = new RecordingConnection();
        $conn->pretendInTransaction = true;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('strong read');

        $this->createQueue($conn)->pop();
    }

    public function test_deleting_a_job_acknowledges_its_message(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message()];

        $queue = $this->createQueue($conn);
        $job = $queue->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $reservedId = $job->getJobId();
        $job->delete();

        $this->assertTrue($job->isDeleted());
        $this->assertSame('delete from `Jobs` where `MessageId` = ?', $conn->recordedAt(3)['sql']);
        $this->assertSame([$reservedId], $conn->recordedAt(3)['bindings']);
    }

    public function test_releasing_a_job_reschedules_it_atomically(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-18 12:00:00'));

        $conn = new RecordingConnection();
        $conn->messages = [$this->message()];

        $queue = $this->createQueue($conn);
        $job = $queue->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $job->release(45);

        $this->assertTrue($job->isReleased());
        $this->assertSame(2, $conn->transactionCount);
        $this->assertSame('delete from `Jobs` where `MessageId` = ?', $conn->recordedAt(3)['sql']);

        $resend = $conn->recordedAt(4);
        $this->assertStringStartsWith('insert into `Jobs`', $resend['sql']);
        $this->assertSame($now->getTimestamp() + 45, $resend['bindings'][2]->getTimestamp());
        // Attempts are not bumped again by a release; the next delivery does that.
        $this->assertSame(1, json_decode($resend['bindings'][1], true)['attempts']);

        Carbon::setTestNow();
    }

    public function test_size_counts_every_message(): void
    {
        $conn = new RecordingConnection();
        $conn->selectResults = [['aggregate' => 7]];

        $this->assertSame(7, $this->createQueue($conn)->size());
        $this->assertSame('select count(*) as `aggregate` from `Jobs`', $conn->recordedAt(0)['sql']);
    }

    public function test_pendingSize_counts_deliverable_messages(): void
    {
        $conn = new RecordingConnection();
        $conn->selectResults = [['aggregate' => 3]];

        $this->assertSame(3, $this->createQueue($conn)->pendingSize());
        $this->assertSame(
            'select count(*) as `aggregate` from `Jobs` where `DeliverTime` <= current_timestamp()',
            $conn->recordedAt(0)['sql'],
        );
    }

    public function test_delayedSize_counts_messages_that_are_not_deliverable_yet(): void
    {
        $conn = new RecordingConnection();
        $conn->selectResults = [['aggregate' => 2]];

        $this->assertSame(2, $this->createQueue($conn)->delayedSize());
        $this->assertSame(
            'select count(*) as `aggregate` from `Jobs` where `DeliverTime` > current_timestamp()',
            $conn->recordedAt(0)['sql'],
        );
    }

    public function test_reservedSize_is_not_distinguishable_from_delayed(): void
    {
        $conn = new RecordingConnection();

        $this->assertSame(0, $this->createQueue($conn)->reservedSize());
        $this->assertSame([], $conn->recorded);
    }

    public function test_creationTimeOfOldestPendingJob_returns_a_unix_timestamp(): void
    {
        $oldest = new DateTimeImmutable('2026-09-18 12:00:00');
        $conn = new RecordingConnection();
        $conn->selectResults = [['oldest' => new Timestamp($oldest)]];

        $this->assertSame(
            $oldest->getTimestamp(),
            $this->createQueue($conn)->creationTimeOfOldestPendingJob(),
        );
        $this->assertSame(
            'select min(`DeliverTime`) as `oldest` from `Jobs` where `DeliverTime` <= current_timestamp()',
            $conn->recordedAt(0)['sql'],
        );
    }

    public function test_creationTimeOfOldestPendingJob_returns_null_when_empty(): void
    {
        $conn = new RecordingConnection();
        $conn->selectResults = [['oldest' => null]];

        $this->assertNull($this->createQueue($conn)->creationTimeOfOldestPendingJob());
    }

    public function test_clear_removes_every_message(): void
    {
        $conn = new RecordingConnection();
        $conn->affectedRows = [0 => 12];

        $this->assertSame(12, $this->createQueue($conn)->clear('Jobs'));
        $this->assertSame('delete from `Jobs` where true', $conn->recordedAt(0)['sql']);
    }

    public function test_queue_name_falls_back_to_the_default(): void
    {
        $queue = $this->createQueue(new RecordingConnection());

        $this->assertSame('Jobs', $queue->getQueue(null));
        $this->assertSame('Jobs', $queue->getQueue(''));
        $this->assertSame('Other', $queue->getQueue('Other'));
    }

    public function test_queue_name_must_be_a_spanner_identifier(): void
    {
        $conn = new RecordingConnection();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid Cloud Spanner identifier');

        $this->createQueue($conn)->size('Jobs`; drop table `User');
    }

    public function test_retry_after_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry_after');

        new SpannerQueue(new RecordingConnection(), 'Jobs', 0);
    }

    public function test_block_for_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('block_for');

        new SpannerQueue(new RecordingConnection(), 'Jobs', 60, 0);
    }

    public function test_interleaved_queue_writes_the_resolved_key_columns(): void
    {
        SpannerQueue::resolveKeysUsing(
            static fn(array $payload) => ['UserId' => $payload['data']['userId']],
        );

        $conn = new RecordingConnection();
        $this->createQueue($conn, keyColumns: ['UserId'])
            ->push('JobClass', ['userId' => 'user-1']);

        $recorded = $conn->recordedAt(0);
        $this->assertSame(
            'insert into `Jobs` (`UserId`, `MessageId`, `Payload`, `DeliverTime`) values (?, ?, ?, ?)',
            $recorded['sql'],
        );
        $this->assertSame('user-1', $recorded['bindings'][0]);
    }

    public function test_interleaved_queue_receives_and_acknowledges_by_full_key(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $job = $this->createQueue($conn, keyColumns: ['UserId'])->pop();

        $this->assertInstanceOf(SpannerJob::class, $job);
        $this->assertSame(
            'select `UserId`, `MessageId`, `Payload`, `SpannerLeaseExpirationTimestamp` ' .
            "from RECEIVE_Jobs(max_duration => '20s')",
            $conn->recordedAt(0)['sql'],
        );

        $ack = $conn->recordedAt(1);
        $this->assertSame('delete from `Jobs` where `UserId` = ? and `MessageId` = ?', $ack['sql']);
        $this->assertSame(['user-1', 'message-1'], $ack['bindings']);
    }

    public function test_reserving_an_interleaved_message_keeps_it_under_the_same_parent(): void
    {
        // The resolver would send the message somewhere else entirely, proving
        // that reservation carries the received key instead of re-resolving it.
        SpannerQueue::resolveKeysUsing(static fn() => ['UserId' => 'wrong-user']);

        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $job = $this->createQueue($conn, keyColumns: ['UserId'])->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $resend = $conn->recordedAt(2);
        $this->assertSame('user-1', $resend['bindings'][0]);
        $this->assertSame($resend['bindings'][1], $job->getJobId());
        $this->assertSame(['UserId' => 'user-1', 'MessageId' => $job->getJobId()], $job->getKey());
    }

    public function test_releasing_an_interleaved_job_keeps_it_under_the_same_parent(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $queue = $this->createQueue($conn, keyColumns: ['UserId']);
        $job = $queue->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $reservedId = $job->getJobId();
        $job->release(30);

        $ack = $conn->recordedAt(3);
        $this->assertSame('delete from `Jobs` where `UserId` = ? and `MessageId` = ?', $ack['sql']);
        $this->assertSame(['user-1', $reservedId], $ack['bindings']);
        $this->assertSame('user-1', $conn->recordedAt(4)['bindings'][0]);
    }

    public function test_deleting_an_interleaved_job_acknowledges_by_full_key(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $job = $this->createQueue($conn, keyColumns: ['UserId'])->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $reservedId = $job->getJobId();
        $job->delete();

        $this->assertSame(['user-1', $reservedId], $conn->recordedAt(3)['bindings']);
    }

    public function test_interleaved_queue_requires_a_resolver(): void
    {
        $conn = new RecordingConnection();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('resolveKeysUsing');

        $this->createQueue($conn, keyColumns: ['UserId'])->pushRaw('{}');
    }

    public function test_resolver_must_supply_every_key_column(): void
    {
        SpannerQueue::resolveKeysUsing(static fn() => []);

        $conn = new RecordingConnection();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key column "UserId" of queue "Jobs" resolved to null');

        $this->createQueue($conn, keyColumns: ['UserId'])->pushRaw('{}');
    }

    public function test_pushRaw_accepts_key_columns_directly(): void
    {
        $conn = new RecordingConnection();

        // No resolver is registered, so the keys must come from the options.
        $this->createQueue($conn, keyColumns: ['UserId'])
            ->pushRaw('{"job":"Foo"}', null, ['keys' => ['UserId' => 'user-9']]);

        $this->assertSame('user-9', $conn->recordedAt(0)['bindings'][0]);
    }

    public function test_key_columns_must_not_repeat_the_message_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain "MessageId"');

        new SpannerQueue(new RecordingConnection(), 'Jobs', 60, 20, false, ['MessageId']);
    }

    public function test_key_columns_must_be_spanner_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key column "UserId`; drop table `User" is not a valid');

        new SpannerQueue(new RecordingConnection(), 'Jobs', 60, 20, false, ['UserId`; drop table `User']);
    }
}
