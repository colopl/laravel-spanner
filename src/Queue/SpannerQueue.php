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

declare(strict_types=1);

namespace Colopl\Spanner\Queue;

use Colopl\Spanner\Connection;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;
use UnitEnum;

use function Illuminate\Support\enum_value;

/**
 * Queue driver backed by Cloud Spanner queues.
 *
 * Each Laravel queue name maps to one Spanner `QUEUE` object, so messages are
 * received through that queue's own `RECEIVE_<queue>()` table valued function.
 *
 * Spanner hands out a message with a fixed, non-configurable 10 second lease,
 * which is far too short for most Laravel jobs, and PHP has no way to renew it
 * from a background thread while a job is running. This driver therefore uses
 * the "acknowledge on arrival, re-send for future delivery" pattern that
 * Google documents for long running work: reserving a message acknowledges it
 * and, in the same transaction, sends a fresh message scheduled `retryAfter`
 * seconds into the future. Completing the job acknowledges that message, while
 * a crashed worker simply lets it become deliverable again. That gives the
 * same visibility timeout semantics as Laravel's other queue drivers.
 *
 * @see https://cloud.google.com/spanner/docs/queues/queues-overview
 * @see https://cloud.google.com/spanner/docs/queues/queues-examples#handle-long-running-work
 */
class SpannerQueue extends BaseQueue implements QueueContract, ClearableQueue
{
    /**
     * Last primary key column of the queue, holding the message's identifier.
     */
    public const string MESSAGE_ID_COLUMN = 'MessageId';

    /**
     * Column holding the serialized Laravel job payload.
     */
    public const string PAYLOAD_COLUMN = 'Payload';

    /**
     * Column defining when a message becomes deliverable. Spanner creates this
     * column implicitly for every queue.
     */
    public const string DELIVER_TIME_COLUMN = 'DeliverTime';

    /**
     * Prefix of the columns that `RECEIVE_<queue>()` adds to every message,
     * such as `SpannerLeaseExpirationTimestamp` and `SpannerLeaseToken`.
     */
    public const string RECEIVE_COLUMN_PREFIX = 'Spanner';

    /**
     * Seconds a reservation outlasts the job's own `timeout`, which is how long
     * the worker needs after killing a job to release or fail it.
     */
    public const int TIMEOUT_MARGIN = 10;

    /**
     * Open `RECEIVE_<queue>()` streams, keyed by queue name.
     *
     * @var array<string, MessageReceiver>
     */
    protected array $receivers = [];

    /**
     * @param Connection $connection
     * @param string $default name of the Spanner queue used when none is given
     * @param int $retryAfter seconds a reserved message stays invisible, unless the job's `timeout` plus {@see self::TIMEOUT_MARGIN} is longer
     * @param int $blockFor seconds a single `RECEIVE_<queue>()` call streams for
     * @param bool $dispatchAfterCommit
     * @param LoggerInterface|null $logger receives the notice for a job that was already processed elsewhere
     */
    public function __construct(
        protected Connection $connection,
        protected string $default,
        protected int $retryAfter = 60,
        protected int $blockFor = 20,
        bool $dispatchAfterCommit = false,
        protected ?LoggerInterface $logger = null,
    ) {
        if ($retryAfter < 1) {
            throw new InvalidArgumentException('retry_after must be at least 1 second.');
        }
        if ($blockFor < 1) {
            throw new InvalidArgumentException('block_for must be at least 1 second.');
        }

        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Number of messages in the queue, including messages which are not
     * deliverable yet.
     *
     * @param UnitEnum|string|null $queue
     * @return int
     */
    public function size($queue = null)
    {
        return $this->count($this->getQueue($queue), null);
    }

    /**
     * @param UnitEnum|string|null $queue
     * @return int
     */
    public function pendingSize($queue = null)
    {
        return $this->count($this->getQueue($queue), '<=');
    }

    /**
     * Number of messages which are not deliverable yet.
     *
     * Note that Spanner queues cannot distinguish a delayed message from a
     * reserved one, since this driver reserves a message by re-sending it for
     * future delivery. In-flight messages are therefore counted here.
     *
     * @param UnitEnum|string|null $queue
     * @return int
     */
    public function delayedSize($queue = null)
    {
        return $this->count($this->getQueue($queue), '>');
    }

    /**
     * Always 0, since reserved messages are indistinguishable from delayed
     * ones and are counted by {@see self::delayedSize()} instead.
     *
     * @param UnitEnum|string|null $queue
     * @return int
     */
    public function reservedSize($queue = null)
    {
        return 0;
    }

    /**
     * @param UnitEnum|string|null $queue
     * @return int|null
     */
    public function creationTimeOfOldestPendingJob($queue = null)
    {
        $oldest = $this->connection->table($this->getQueue($queue))
            ->where(self::DELIVER_TIME_COLUMN, '<=', $this->connection->raw('current_timestamp()'))
            ->min(self::DELIVER_TIME_COLUMN);

        // The query builder turns a Spanner Timestamp into a Carbon instance.
        return $oldest instanceof DateTimeInterface
            ? $oldest->getTimestamp()
            : null;
    }

    /**
     * {@inheritDoc}
     * @param UnitEnum|string|null $queue
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->later(null, $job, $data, $queue);
    }

    /**
     * {@inheritDoc}
     * @param array{ keys?: array<string, mixed> } $options interleave key values, for an interleaved queue
     */
    public function pushRaw($payload, $queue = null, array $options = []): string
    {
        $key = $this->pushToDatabase($this->getQueue($queue), $payload, 0, $options['keys'] ?? []);

        return $this->messageIdOf($key);
    }

    /**
     * {@inheritDoc}
     * @param DateTimeInterface|DateInterval|int|null $delay
     * @param UnitEnum|string|null $queue
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $queue = $this->getQueue($queue);
        $interleaveKey = $job instanceof ProvidesInterleaveKeys ? $job->getInterleaveKeys() : [];

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data, $delay),
            $queue,
            $delay,
            function (string $payload, string $queue, $delay) use ($interleaveKey): string {
                $key = $this->pushToDatabase($queue, $payload, $delay, $interleaveKey);
                return $this->messageIdOf($key);
            },
        );
    }

    /**
     * Pushes every job in a single Spanner transaction, so that either all or
     * none of them are sent.
     *
     * Jobs dispatched after commit (`after_commit`, `ShouldQueueAfterCommit`)
     * are the exception: Laravel holds each of them back until the transaction
     * commits and then sends them one by one, so they are not sent atomically.
     *
     * {@inheritDoc}
     * @param array<array-key, mixed> $jobs
     * @param UnitEnum|string|null $queue
     */
    public function bulk($jobs, $data = '', $queue = null)
    {
        $this->connection->transaction(function () use ($jobs, $data, $queue): void {
            foreach ($jobs as $job) {
                if (!is_object($job) && !is_string($job)) {
                    throw new InvalidArgumentException('Each job must either be an object or a class name.');
                }

                $delay = is_object($job)
                    ? $this->getAttributeValue($job, Delay::class, 'delay')
                    : null;

                if ($delay instanceof DateTimeInterface || $delay instanceof DateInterval || is_int($delay)) {
                    $this->later($delay, $job, $data, $queue);
                } else {
                    $this->push($job, $data, $queue);
                }
            }
        });
    }

    /**
     * {@inheritDoc}
     * @param UnitEnum|string|null $queue
     * @return JobContract|null
     * @throws UndecodablePayloadException after deleting a message whose payload is not a JSON object
     * @throws LogicException inside a transaction or a snapshot
     */
    public function pop($queue = null)
    {
        // Checked on every call, not only when a stream is opened: reading a
        // stream held from an earlier call would otherwise reserve its message
        // inside the transaction, which a rollback would undo after the job
        // was already handed out.
        if ($this->connection->inTransaction() || $this->connection->inSnapshot()) {
            throw new LogicException(
                'Messages cannot be received inside a transaction or a snapshot, ' .
                'because Cloud Spanner rejects anything but a strong read.',
            );
        }

        $queue = $this->getQueue($queue);

        while (($message = $this->receiver($queue)->next()) !== null) {
            $job = $this->reserve($queue, $message);
            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Acknowledges a message, removing it from the queue.
     *
     * @param string $queue
     * @param array<string, mixed> $key full primary key of the message
     * @return int number of messages that were acknowledged (0 or 1)
     */
    public function acknowledge(string $queue, array $key): int
    {
        $query = $this->connection->table($queue);
        foreach ($key as $column => $value) {
            $query->where($column, $value);
        }
        return $query->delete();
    }

    /**
     * Deletes the reserved message of a finished job.
     *
     * When nothing was deleted, the reservation had already run out and
     * another worker took the job, so it is running or has run twice.
     *
     * @param string $queue
     * @param array<string, mixed> $key full primary key of the reserved message
     * @return void
     */
    public function deleteReserved(string $queue, array $key): void
    {
        if ($this->acknowledge($queue, $key) === 0) {
            $this->noticeAlreadyProcessed('deleted', $queue, $key);
        }
    }

    /**
     * Acknowledges a reserved message and sends its payload back to the queue,
     * atomically, so the job is neither lost nor duplicated.
     *
     * When nothing was acknowledged, another worker already took the job, so
     * sending it again would only add a copy of it.
     *
     * @param string $queue
     * @param SpannerJob $job
     * @param DateTimeInterface|DateInterval|int $delay
     * @return void
     * @throws Throwable
     */
    public function deleteAndRelease(string $queue, SpannerJob $job, $delay = 0): void
    {
        $key = $job->getKey();

        $released = $this->connection->transaction(function () use ($queue, $job, $key, $delay): bool {
            if ($this->acknowledge($queue, $key) === 0) {
                return false;
            }
            $this->pushToDatabase($queue, $job->getRawBody(), $delay, $this->interleaveKeyValuesOf($key));
            return true;
        });

        if (!$released) {
            $this->noticeAlreadyProcessed('released', $queue, $key);
        }
    }

    /**
     * Logs that a finished job's reserved message was gone, because the
     * reservation ran out and another worker took the job.
     *
     * @param string $action what could not be done with the job
     * @param string $queue
     * @param array<string, mixed> $key full primary key of the reserved message
     * @return void
     */
    protected function noticeAlreadyProcessed(string $action, string $queue, array $key): void
    {
        $this->logger?->notice(sprintf(
            'Job "%s" of queue "%s" could not be %s because another worker already processed it. ' .
            'Its reservation (retry_after of %d seconds, or longer when the job declares a timeout) ' .
            'ran out while it was still running.',
            $this->messageIdOf($key),
            $queue,
            $action,
            $this->retryAfter,
        ));
    }

    /**
     * @inheritDoc
     */
    public function clear($queue)
    {
        $queue = $this->getQueue($queue);

        // Messages its stream already received would otherwise still be handed
        // out after the clear. Streams of other queues stay open.
        if (isset($this->receivers[$queue])) {
            $this->receivers[$queue]->close();
            unset($this->receivers[$queue]);
        }

        return $this->connection->table($queue)
            ->whereRaw('true')
            ->delete();
    }

    /**
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Resolves the name of the Spanner queue to operate on.
     *
     * @param UnitEnum|string|null $queue
     * @return string
     */
    public function getQueue($queue): string
    {
        $value = enum_value($queue);

        return is_string($value) && $value !== ''
            ? $value
            : $this->default;
    }

    /**
     * Sends a message to the queue, optionally scheduled for future delivery.
     *
     * When called while a transaction is open, the message is only delivered
     * once that transaction commits.
     *
     * @param string $queue
     * @param string $payload
     * @param DateTimeInterface|DateInterval|int|null $delay null when pushed without a delay
     * @param array<string, mixed> $interleaveKey values of the key columns in front of `MessageId`
     * @return array<string, mixed> full primary key of the sent message
     */
    protected function pushToDatabase(string $queue, string $payload, $delay = 0, array $interleaveKey = []): array
    {
        $key = $interleaveKey;
        $key[self::MESSAGE_ID_COLUMN] = Str::uuid()->toString();

        $values = $key;
        $values[self::PAYLOAD_COLUMN] = $payload;
        $values[self::DELIVER_TIME_COLUMN] = new DateTimeImmutable('@' . $this->availableAt($delay ?? 0));

        $this->connection->table($queue)->insert($values);

        return $key;
    }

    /**
     * The key columns in front of `MessageId` of a message's primary key,
     * which a message sent in its place has to keep, so that a job stays under
     * the same parent row of an interleaved queue.
     *
     * @param array<string, mixed> $key full primary key of a message
     * @return array<string, mixed>
     */
    protected function interleaveKeyValuesOf(array $key): array
    {
        unset($key[self::MESSAGE_ID_COLUMN]);
        return $key;
    }

    /**
     * The `MessageId` of a message's primary key, which the driver always
     * generates as a UUID string.
     *
     * @param array<string, mixed> $key full primary key of a message
     * @return string
     */
    protected function messageIdOf(array $key): string
    {
        $messageId = $key[self::MESSAGE_ID_COLUMN];
        assert(is_string($messageId));
        return $messageId;
    }

    /**
     * Turns a received message into a reserved job.
     *
     * @param string $queue
     * @param array<array-key, mixed> $message
     * @return SpannerJob|null null when the message was already taken by another worker
     * @throws UndecodablePayloadException after deleting a message whose payload is not a JSON object
     */
    protected function reserve(string $queue, array $message): ?SpannerJob
    {
        $key = $this->extractKeyFromMessage($message);
        $rawPayload = $message[self::PAYLOAD_COLUMN];
        assert(is_string($rawPayload));

        // Decoded once for both the attempt counter and the job's timeout.
        $decoded = json_decode($rawPayload, true);

        if (!is_array($decoded)) {
            $reason = json_last_error() !== JSON_ERROR_NONE
                ? json_last_error_msg()
                : 'it is JSON ' . get_debug_type($decoded);

            // When nothing was deleted, another worker already deleted and reported it.
            if ($this->acknowledge($queue, $key) === 0) {
                return null;
            }

            // No worker can run it, so it is deleted rather than redelivered
            // forever, and the worker reports the exception with the payload.
            throw new UndecodablePayloadException(sprintf(
                'Message "%s" of queue "%s" was deleted because its payload is not a JSON object (%s), so no ' .
                'worker can run it.',
                $this->messageIdOf($key),
                $queue,
                $reason,
            ), $queue, $key, $rawPayload);
        }

        $payload = $this->incrementAttempts($rawPayload, $decoded);
        $reservedFor = $this->reservationFor($decoded);
        $interleaveKey = $this->interleaveKeyValuesOf($key);

        $reservedKey = $this->connection->transaction(function () use ($queue, $key, $payload, $reservedFor, $interleaveKey): ?array {
            if ($this->acknowledge($queue, $key) === 0) {
                // The message is already gone, so it was handled elsewhere.
                return null;
            }
            return $this->pushToDatabase($queue, $payload, $reservedFor, $interleaveKey);
        });

        return $reservedKey !== null
            ? new SpannerJob($this->container, $this, $payload, $reservedKey, $queue)
            : null;
    }

    /**
     * Primary key of a received message.
     *
     * Every column of a queue other than `Payload` is part of its primary key,
     * so the key is whatever the message has besides `Payload`, `DeliverTime`
     * and the columns `RECEIVE_<queue>()` adds. A queue that declares
     * `DeliverTime` as a key column, for a row deletion policy, is acknowledged
     * without it, which still matches the single message with its `MessageId`.
     *
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    protected function extractKeyFromMessage(array $message): array
    {
        $key = [];
        foreach ($message as $column => $value) {
            $column = (string) $column;
            if ($column === self::PAYLOAD_COLUMN
                || $column === self::DELIVER_TIME_COLUMN
                || str_starts_with($column, self::RECEIVE_COLUMN_PREFIX)
            ) {
                continue;
            }
            $key[$column] = $value;
        }
        return $key;
    }

    /**
     * Seconds a reserved message stays invisible: `retry_after`, or the job's
     * own `timeout` (which Laravel writes into the payload) plus a margin when
     * that is longer, so a job allowed to run for minutes is not delivered to a
     * second worker after `retry_after` seconds.
     *
     * @param array<array-key, mixed> $decoded the decoded payload
     * @return int
     */
    protected function reservationFor(array $decoded): int
    {
        $timeout = $decoded['timeout'] ?? null;

        return is_int($timeout) && $timeout > 0
            ? max($this->retryAfter, $timeout + self::TIMEOUT_MARGIN)
            : $this->retryAfter;
    }

    /**
     * Records that the payload is about to be handed to a worker.
     *
     * Every delivery goes through {@see self::reserve()}, so this stays
     * accurate even when a worker crashes without releasing the job.
     *
     * @param string $payload
     * @param array<array-key, mixed> $decoded the decoded payload
     * @return string
     */
    protected function incrementAttempts(string $payload, array $decoded): string
    {
        $attempts = $decoded['attempts'] ?? 0;
        $decoded['attempts'] = (is_int($attempts) ? $attempts : 0) + 1;
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        return $encoded !== false
            ? $encoded
            : $payload;
    }

    /**
     * @param string $queue
     * @return MessageReceiver
     */
    protected function receiver(string $queue): MessageReceiver
    {
        return $this->receivers[$queue] ??= new MessageReceiver(
            fn(): Generator => $this->receive($queue),
            $this->blockFor,
        );
    }

    /**
     * Opens a `RECEIVE_<queue>()` stream, as a single use strong read.
     *
     * @param string $queue
     * @return Generator<int, array<array-key, mixed>>
     */
    protected function receive(string $queue): Generator
    {
        // Every column, so that a message brings its whole primary key along,
        // whatever key columns an interleaved queue has.
        $sql = sprintf(
            'select * from %s(max_duration => %s)',
            'RECEIVE_' . $this->qualifyQueue($queue),
            $this->connection->getQueryGrammar()->quoteString($this->blockFor . 's'),
        );

        return $this->connection->cursorWithOptions($sql, [], [
            // Forces a single use strong read instead of joining a transaction.
            'singleUse' => true,
            // The stream stays open for `max_duration`, so the RPC deadline has
            // to outlive it regardless of `client.requestTimeout`.
            'timeoutMillis' => ($this->blockFor + 10) * 1000,
        ]);
    }

    /**
     * Number of messages matching the given delivery time comparison.
     *
     * @param string $queue
     * @param string|null $operator compared against the current timestamp
     * @return int
     */
    protected function count(string $queue, ?string $operator): int
    {
        $query = $this->connection->table($queue);
        if ($operator !== null) {
            $query->where(self::DELIVER_TIME_COLUMN, $operator, $this->connection->raw('current_timestamp()'));
        }
        return $query->count();
    }

    /**
     * Applies the connection's table prefix and rejects anything that is not a
     * plain Spanner identifier, since a queue name has to be interpolated into
     * the `RECEIVE_<queue>()` function name, where it cannot be bound.
     *
     * @param string $queue
     * @return string
     */
    protected function qualifyQueue(string $queue): string
    {
        $name = $this->connection->getTablePrefix() . $queue;

        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $name) !== 1) {
            throw new InvalidArgumentException("Queue name \"{$name}\" is not a valid Cloud Spanner identifier.");
        }

        return $name;
    }
}
