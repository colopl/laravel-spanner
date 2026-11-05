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

use Colopl\Spanner\Queue\ProvidesInterleaveKeys;
use Colopl\Spanner\Queue\SpannerJob;
use Colopl\Spanner\Queue\SpannerQueue;
use Colopl\Spanner\Queue\UndecodablePayloadException;
use Colopl\Spanner\Tests\Support\RecordingConnection;
use Colopl\Spanner\Tests\Support\RecordingLogger;
use Colopl\Spanner\Tests\TestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Spanner\Timestamp;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

class SpannerQueueTest extends TestCase
{
    protected const QUEUE_NAME = 'Jobs';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function createQueue(
        RecordingConnection $connection,
        int $retryAfter = 60,
        int $blockFor = 20,
        ?LoggerInterface $logger = null,
    ): SpannerQueue {
        $queue = new SpannerQueue(
            $connection,
            self::QUEUE_NAME,
            $retryAfter,
            $blockFor,
            false,
            $logger,
        );
        $queue->setContainer(Container::getInstance());
        $queue->setConnectionName('spanner');

        return $queue;
    }

    /**
     * A row as `select *` from `RECEIVE_<queue>()` returns it: the queue's own
     * columns, then the ones the function adds.
     *
     * @param array<string, mixed> $overrides
     * @return array<array-key, mixed>
     */
    protected function message(array $overrides = []): array
    {
        $now = new Timestamp(new DateTimeImmutable());

        return $overrides + [
            SpannerQueue::MESSAGE_ID_COLUMN => 'message-1',
            SpannerQueue::PAYLOAD_COLUMN => json_encode(['uuid' => 'job-uuid', 'attempts' => 0]),
            SpannerQueue::DELIVER_TIME_COLUMN => $now,
            'SpannerLeaseExpirationTimestamp' => $now,
            'SpannerLeaseToken' => 'lease-token',
            'SpannerLastBatchMessage' => true,
        ];
    }

    /**
     * Values of a recorded insert by column name, since the query builder
     * orders the columns alphabetically.
     *
     * @return array<string, mixed>
     */
    protected function insertedRow(RecordingConnection $conn, int $index): array
    {
        $recorded = $conn->recordedAt($index);
        preg_match('/\((.+?)\) values/', $recorded['sql'], $matches);
        $columns = array_map(static fn(string $column): string => trim($column, ' `'), explode(',', $matches[1]));

        return array_combine($columns, $recorded['bindings']);
    }

    public function test_pushRaw_sends_a_message_deliverable_now(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-18 12:00:00'));

        $conn = new RecordingConnection();
        $id = $this->createQueue($conn)->pushRaw('{"job":"Foo"}');

        $this->assertSame(
            'insert into `Jobs` (`DeliverTime`, `MessageId`, `Payload`) values (?, ?, ?)',
            $conn->recordedAt(0)['sql'],
        );
        $row = $this->insertedRow($conn, 0);
        $this->assertSame($id, $row['MessageId']);
        $this->assertSame('{"job":"Foo"}', $row['Payload']);
        $this->assertInstanceOf(DateTimeInterface::class, $row['DeliverTime']);
        $this->assertSame($now->getTimestamp(), $row['DeliverTime']->getTimestamp());

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

        $deliverTime = $this->insertedRow($conn, 0)['DeliverTime'];
        $this->assertInstanceOf(DateTimeInterface::class, $deliverTime);
        $this->assertSame($now->getTimestamp() + 90, $deliverTime->getTimestamp());

        Carbon::setTestNow();
    }

    public function test_push_stores_a_payload_the_worker_can_decode(): void
    {
        $conn = new RecordingConnection();
        $this->createQueue($conn)->push('JobClass', ['a' => 1]);

        $payload = json_decode($this->insertedRow($conn, 0)['Payload'], true);
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
            SpannerQueue::PAYLOAD_COLUMN => $this->insertedRow($conn, 0)['Payload'],
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
        // Every column, so a message brings its whole primary key along.
        $this->assertSame("select * from RECEIVE_Jobs(max_duration => '30s')", $receive['sql']);
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

        $this->assertStringStartsWith('insert into `Jobs`', $conn->recordedAt(2)['sql']);
        $resend = $this->insertedRow($conn, 2);
        $this->assertSame($now->getTimestamp() + 120, $resend['DeliverTime']->getTimestamp());

        // The reserved message is the one the job now refers to.
        $this->assertSame($resend['MessageId'], $job->getJobId());
        $this->assertNotSame('message-1', $job->getJobId());

        Carbon::setTestNow();
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function reservationTimeouts(): array
    {
        return [
            'a timeout beyond retry_after extends the reservation' => [300, 310],
            'a timeout within retry_after keeps retry_after' => [30, 120],
            'a timeout that only just fits keeps retry_after' => [110, 120],
            'a timeout just over retry_after minus the margin extends it' => [111, 121],
            'no timeout keeps retry_after' => [null, 120],
            'a zero timeout keeps retry_after' => [0, 120],
            'a non numeric timeout keeps retry_after' => ['300', 120],
        ];
    }

    #[DataProvider('reservationTimeouts')]
    public function test_the_reservation_outlasts_the_timeout_of_the_job(mixed $timeout, int $reservedFor): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-18 12:00:00'));

        $conn = new RecordingConnection();
        $conn->messages = [$this->message([
            SpannerQueue::PAYLOAD_COLUMN => json_encode(['uuid' => 'job-uuid', 'attempts' => 0, 'timeout' => $timeout]),
        ])];

        $this->assertInstanceOf(SpannerJob::class, $this->createQueue($conn, retryAfter: 120)->pop());

        $this->assertSame($now->getTimestamp() + $reservedFor, $this->insertedRow($conn, 2)['DeliverTime']->getTimestamp());

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
        $this->assertSame(3, json_decode($this->insertedRow($conn, 2)['Payload'], true)['attempts']);
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

    public function test_pop_listens_on_a_fresh_stream_once_the_held_one_has_run_out_of_time(): void
    {
        Carbon::setTestNow($start = Carbon::parse('2026-10-26 12:00:00'));

        $conn = new RecordingConnection();
        $conn->messages = [$this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-1'])];

        $queue = $this->createQueue($conn, blockFor: 20);
        $this->assertInstanceOf(SpannerJob::class, $queue->pop());

        // The job ran for longer than `block_for`, so the stream ended while the
        // worker was busy. Consulting it would report an empty queue instantly
        // and send the worker to sleep with this message already waiting.
        Carbon::setTestNow($start->copy()->addSeconds(25));
        $conn->messages = [$this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-2'])];

        $this->assertInstanceOf(SpannerJob::class, $queue->pop());
        $this->assertSame(2, $conn->streamsOpened);
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
            'insert into `Jobs` (`DeliverTime`, `MessageId`, `Payload`) values (?, ?, ?)',
        ], array_slice($conn->recordedSql(), 1));
        $this->assertSame(['mine'], $conn->recordedAt(2)['bindings']);
    }

    public function test_pop_deletes_a_message_that_is_not_json_and_throws(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'broken', SpannerQueue::PAYLOAD_COLUMN => 'not json']),
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'fine']),
        ];
        $queue = $this->createQueue($conn);

        try {
            $queue->pop();
            $this->fail('Expected an UndecodablePayloadException.');
        } catch (UndecodablePayloadException $e) {
            $this->assertSame(
                'Message "broken" of queue "Jobs" was deleted because its payload is not a JSON object ' .
                '(Syntax error), so no worker can run it.',
                $e->getMessage(),
            );
            // Laravel's exception handler logs this along with the exception.
            $this->assertSame(
                ['queue' => 'Jobs', 'key' => [SpannerQueue::MESSAGE_ID_COLUMN => 'broken'], 'payload' => 'not json'],
                $e->context(),
            );
        }

        $this->assertSame(['delete from `Jobs` where `MessageId` = ?'], array_slice($conn->recordedSql(), 1));
        $this->assertSame(['broken'], $conn->recordedAt(1)['bindings']);

        // The stream is kept, so the next pop carries on with the next message.
        $this->assertInstanceOf(SpannerJob::class, $queue->pop());
        $this->assertSame(['fine'], $conn->recordedAt(2)['bindings']);
        $this->assertSame(1, $conn->streamsOpened);
    }

    public function test_pop_deletes_a_message_whose_payload_is_json_but_not_an_object(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message([SpannerQueue::PAYLOAD_COLUMN => '"hello"'])];

        $this->expectException(UndecodablePayloadException::class);
        $this->expectExceptionMessage('(it is JSON string)');

        try {
            $this->createQueue($conn)->pop();
        } finally {
            $this->assertSame(['delete from `Jobs` where `MessageId` = ?'], array_slice($conn->recordedSql(), 1));
        }
    }

    public function test_a_message_that_is_not_json_is_reported_only_by_the_worker_that_deletes_it(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message([SpannerQueue::PAYLOAD_COLUMN => 'not json'])];
        // Another worker deleted it first.
        $conn->affectedRows = [1 => 0];

        $this->assertNull($this->createQueue($conn)->pop());
    }

    public function test_pop_rejects_receiving_inside_a_transaction(): void
    {
        $conn = new RecordingConnection();
        $conn->pretendInTransaction = true;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('strong read');

        $this->createQueue($conn)->pop();
    }

    public function test_pop_rejects_reading_a_held_stream_inside_a_transaction(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-1']),
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-2']),
        ];

        $queue = $this->createQueue($conn);
        $this->assertInstanceOf(SpannerJob::class, $queue->pop());
        $recorded = count($conn->recorded);

        // The stream opened outside the transaction still holds message-2.
        // Reserving it inside the transaction would let a rollback restore it
        // after the job was already handed out.
        $conn->pretendInTransaction = true;
        try {
            $queue->pop();
            $this->fail('pop() read a held stream inside a transaction.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('strong read', $e->getMessage());
        }
        $this->assertCount($recorded, $conn->recorded);
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

    public function test_deleting_a_job_that_another_worker_already_processed_logs_a_notice(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message()];
        // The delete of the reserved message (after receive, reserve's delete and insert) removes nothing.
        $conn->affectedRows = [3 => 0];
        $logger = new RecordingLogger();

        $job = $this->createQueue($conn, retryAfter: 90, logger: $logger)->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);
        $job->delete();

        $this->assertTrue($job->isDeleted());
        $this->assertCount(1, $logger->records);
        $this->assertSame('notice', $logger->records[0]['level']);
        $this->assertStringContainsString(
            sprintf('Job "%s" of queue "Jobs" could not be deleted', $job->getJobId()),
            $logger->records[0]['message'],
        );
        $this->assertStringContainsString('retry_after of 90 seconds', $logger->records[0]['message']);
    }

    public function test_deleting_a_job_normally_logs_nothing(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message()];
        $logger = new RecordingLogger();

        $job = $this->createQueue($conn, logger: $logger)->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);
        $job->delete();

        $this->assertSame([], $logger->records);
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

        $this->assertStringStartsWith('insert into `Jobs`', $conn->recordedAt(4)['sql']);
        $resend = $this->insertedRow($conn, 4);
        $this->assertSame($now->getTimestamp() + 45, $resend['DeliverTime']->getTimestamp());
        // Attempts are not bumped again by a release; the next delivery does that.
        $this->assertSame(1, json_decode($resend['Payload'], true)['attempts']);

        Carbon::setTestNow();
    }

    public function test_releasing_a_job_that_another_worker_already_processed_sends_no_copy(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message()];
        // The delete of the reserved message (after receive, reserve's delete and insert) removes nothing.
        $conn->affectedRows = [3 => 0];
        $logger = new RecordingLogger();

        $job = $this->createQueue($conn, logger: $logger)->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);
        $job->release(45);

        $this->assertTrue($job->isReleased());
        // Only the delete ran; the payload was not sent again.
        $this->assertCount(4, $conn->recorded);
        $this->assertStringStartsWith('delete from `Jobs`', $conn->recordedAt(3)['sql']);
        $this->assertCount(1, $logger->records);
        $this->assertSame('notice', $logger->records[0]['level']);
        $this->assertStringContainsString(
            sprintf('Job "%s" of queue "Jobs" could not be released', $job->getJobId()),
            $logger->records[0]['message'],
        );
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
        $conn->selectResults = [['aggregate' => new Timestamp($oldest)]];

        $this->assertSame(
            $oldest->getTimestamp(),
            $this->createQueue($conn)->creationTimeOfOldestPendingJob(),
        );
        $this->assertSame(
            'select min(`DeliverTime`) as `aggregate` from `Jobs` where `DeliverTime` <= current_timestamp()',
            $conn->recordedAt(0)['sql'],
        );
    }

    public function test_creationTimeOfOldestPendingJob_returns_null_when_empty(): void
    {
        $conn = new RecordingConnection();
        $conn->selectResults = [['aggregate' => null]];

        $this->assertNull($this->createQueue($conn)->creationTimeOfOldestPendingJob());
    }

    public function test_clear_removes_every_message(): void
    {
        $conn = new RecordingConnection();
        $conn->affectedRows = [0 => 12];

        $this->assertSame(12, $this->createQueue($conn)->clear('Jobs'));
        $this->assertSame('delete from `Jobs` where true', $conn->recordedAt(0)['sql']);
    }

    public function test_clear_drops_the_messages_the_open_stream_already_received(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-1']),
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-2']),
        ];

        $queue = $this->createQueue($conn);
        $this->assertInstanceOf(SpannerJob::class, $queue->pop());
        $conn->messages = [];
        $queue->clear('Jobs');

        // message-2 was received before the clear, so it must not be handed out.
        $this->assertNull($queue->pop());
        $this->assertSame(2, $conn->streamsOpened);
    }

    public function test_clear_keeps_the_streams_of_other_queues_open(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-1']),
            $this->message([SpannerQueue::MESSAGE_ID_COLUMN => 'message-2']),
        ];

        $queue = $this->createQueue($conn);
        $this->assertInstanceOf(SpannerJob::class, $queue->pop('Other'));
        $conn->messages = [];
        $queue->clear('Jobs');

        $this->assertInstanceOf(SpannerJob::class, $queue->pop('Other'));
        $this->assertSame(1, $conn->streamsOpened);
        // Recorded: receive, reserve message-1 (delete, insert), clear, reserve message-2.
        $this->assertSame('delete from `Other` where `MessageId` = ?', $conn->recordedAt(4)['sql']);
        $this->assertSame(['message-2'], $conn->recordedAt(4)['bindings']);
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

        $this->createQueue($conn)->pop('Jobs`; drop table `User');
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

    public function test_a_job_provides_the_interleave_keys_it_is_sent_with(): void
    {
        $conn = new RecordingConnection();
        $this->createQueue($conn)->push(new FakeJobProvidingInterleaveKeys('user-1'));

        $this->assertSame(
            'insert into `Jobs` (`DeliverTime`, `MessageId`, `Payload`, `UserId`) values (?, ?, ?, ?)',
            $conn->recordedAt(0)['sql'],
        );
        $this->assertSame('user-1', $this->insertedRow($conn, 0)['UserId']);
    }

    public function test_queueable_options_carry_the_interleave_keys_for_queue_retry(): void
    {
        $queue = $this->createQueue(new RecordingConnection());

        // `queue:retry` passes these options to pushRaw() for a failed job.
        $this->assertSame(
            ['keys' => ['UserId' => 'user-1']],
            $queue->getQueueableOptions(new FakeJobProvidingInterleaveKeys('user-1'), 'Jobs', '{}'),
        );
        $this->assertSame([], $queue->getQueueableOptions(new stdClass(), 'Jobs', '{}'));
        $this->assertSame([], $queue->getQueueableOptions('SomeJob@handle', 'Jobs', '{}'));
    }

    public function test_later_takes_the_interleave_keys_from_the_job_too(): void
    {
        $conn = new RecordingConnection();
        $this->createQueue($conn)->later(60, new FakeJobProvidingInterleaveKeys('user-7'));

        $this->assertSame('user-7', $this->insertedRow($conn, 0)['UserId']);
    }

    public function test_a_job_without_the_interface_is_sent_without_interleave_keys(): void
    {
        $conn = new RecordingConnection();
        // Neither the payload data nor a property is looked into.
        $this->createQueue($conn)->push('JobClass', ['UserId' => 'user-1']);

        $this->assertSame(
            'insert into `Jobs` (`DeliverTime`, `MessageId`, `Payload`) values (?, ?, ?)',
            $conn->recordedAt(0)['sql'],
        );
    }

    public function test_pushRaw_accepts_interleave_keys_directly(): void
    {
        $conn = new RecordingConnection();
        $this->createQueue($conn)->pushRaw('{"job":"Foo"}', null, ['keys' => ['UserId' => 'user-9']]);

        $this->assertSame('user-9', $this->insertedRow($conn, 0)['UserId']);
    }

    public function test_interleaved_queue_receives_and_acknowledges_by_full_key(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $job = $this->createQueue($conn)->pop();

        $this->assertInstanceOf(SpannerJob::class, $job);
        $ack = $conn->recordedAt(1);
        // Payload, DeliverTime and the columns RECEIVE adds are not part of the key.
        $this->assertSame('delete from `Jobs` where `UserId` = ? and `MessageId` = ?', $ack['sql']);
        $this->assertSame(['user-1', 'message-1'], $ack['bindings']);
    }

    public function test_reserving_an_interleaved_message_keeps_it_under_the_same_parent(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $job = $this->createQueue($conn)->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $resend = $this->insertedRow($conn, 2);
        $this->assertSame('user-1', $resend['UserId']);
        $this->assertSame($resend['MessageId'], $job->getJobId());
        $this->assertSame(['UserId' => 'user-1', 'MessageId' => $job->getJobId()], $job->getKey());
    }

    public function test_releasing_an_interleaved_job_keeps_it_under_the_same_parent(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $job = $this->createQueue($conn)->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $reservedId = $job->getJobId();
        $job->release(30);

        $ack = $conn->recordedAt(3);
        $this->assertSame('delete from `Jobs` where `UserId` = ? and `MessageId` = ?', $ack['sql']);
        $this->assertSame(['user-1', $reservedId], $ack['bindings']);
        $this->assertSame('user-1', $this->insertedRow($conn, 4)['UserId']);
    }

    public function test_deleting_an_interleaved_job_acknowledges_by_full_key(): void
    {
        $conn = new RecordingConnection();
        $conn->messages = [$this->message(['UserId' => 'user-1'])];

        $job = $this->createQueue($conn)->pop();
        $this->assertInstanceOf(SpannerJob::class, $job);

        $reservedId = $job->getJobId();
        $job->delete();

        $this->assertSame(['user-1', $reservedId], $conn->recordedAt(3)['bindings']);
    }
}

class FakeJobProvidingInterleaveKeys implements ProvidesInterleaveKeys
{
    public function __construct(
        private string $userId,
    ) {
    }

    public function getInterleaveKeys(): array
    {
        return ['UserId' => $this->userId];
    }
}
