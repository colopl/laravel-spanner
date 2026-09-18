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
use Google\Cloud\Spanner\Timestamp;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
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
     * Primary key column of the queue, holding the message's identifier.
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
     * Column of `RECEIVE_<queue>()` holding the lease expiration.
     */
    public const string LEASE_EXPIRATION_COLUMN = 'SpannerLeaseExpirationTimestamp';

    /**
     * Open `RECEIVE_<queue>()` streams, keyed by queue name.
     *
     * @var array<string, MessageReceiver>
     */
    protected array $receivers = [];

    /**
     * @param Connection $connection
     * @param string $default name of the Spanner queue used when none is given
     * @param int $retryAfter seconds a reserved message stays invisible
     * @param int $blockFor seconds a single `RECEIVE_<queue>()` call streams for
     * @param bool $dispatchAfterCommit
     */
    public function __construct(
        protected Connection $connection,
        protected string $default,
        protected int $retryAfter = 60,
        protected int $blockFor = 20,
        bool $dispatchAfterCommit = false,
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
        $row = $this->connection->selectOne(sprintf(
            'select min(%s) as `oldest` from %s where %s <= current_timestamp()',
            $this->wrapIdentifier(self::DELIVER_TIME_COLUMN),
            $this->wrapQueue($this->getQueue($queue)),
            $this->wrapIdentifier(self::DELIVER_TIME_COLUMN),
        ));

        $oldest = is_array($row) ? ($row['oldest'] ?? null) : null;

        return $oldest instanceof Timestamp
            ? $oldest->get()->getTimestamp()
            : null;
    }

    /**
     * {@inheritDoc}
     * @param UnitEnum|string|null $queue
     */
    public function push($job, $data = '', $queue = null)
    {
        $queue = $this->getQueue($queue);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data),
            $queue,
            null,
            fn(string $payload, string $queue): string => $this->send($queue, $payload, 0),
        );
    }

    /**
     * {@inheritDoc}
     * @param UnitEnum|string|null $queue
     * @param array<string, mixed> $options
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->send($this->getQueue($queue), $payload, 0);
    }

    /**
     * {@inheritDoc}
     * @param DateTimeInterface|DateInterval|int $delay
     * @param UnitEnum|string|null $queue
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $queue = $this->getQueue($queue);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data, $delay),
            $queue,
            $delay,
            fn(string $payload, string $queue, $delay): string => $this->send($queue, $payload, $delay),
        );
    }

    /**
     * Pushes every job in a single Spanner transaction, so that either all or
     * none of them are sent.
     *
     * {@inheritDoc}
     * @param iterable<array-key, mixed> $jobs
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
     */
    public function pop($queue = null)
    {
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
     * @param string $messageId
     * @return int number of messages that were acknowledged (0 or 1)
     */
    public function acknowledge(string $queue, string $messageId): int
    {
        return $this->connection->affectingStatement(
            sprintf(
                'delete from %s where %s = ?',
                $this->wrapQueue($queue),
                $this->wrapIdentifier(self::MESSAGE_ID_COLUMN),
            ),
            [$messageId],
        );
    }

    /**
     * Acknowledges a reserved message and sends its payload back to the queue,
     * atomically, so the job is neither lost nor duplicated.
     *
     * @param string $queue
     * @param SpannerJob $job
     * @param DateTimeInterface|DateInterval|int $delay
     * @return void
     */
    public function deleteAndRelease(string $queue, SpannerJob $job, $delay = 0): void
    {
        $this->connection->transaction(function () use ($queue, $job, $delay): void {
            $this->acknowledge($queue, (string) $job->getJobId());
            $this->send($queue, $job->getRawBody(), $delay);
        });
    }

    /**
     * {@inheritDoc}
     * @param UnitEnum|string|null $queue
     */
    public function clear($queue)
    {
        $this->stopReceiving();

        return $this->connection->affectingStatement(
            sprintf('delete from %s where true', $this->wrapQueue($this->getQueue($queue))),
        );
    }

    /**
     * Closes every open `RECEIVE_<queue>()` stream.
     *
     * @return void
     */
    public function stopReceiving(): void
    {
        foreach ($this->receivers as $receiver) {
            $receiver->close();
        }
        $this->receivers = [];
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
     * @param DateTimeInterface|DateInterval|int $delay
     * @return string the identifier of the sent message
     */
    protected function send(string $queue, string $payload, $delay = 0): string
    {
        $messageId = (string) Str::uuid();

        $this->connection->affectingStatement(
            sprintf(
                'insert into %s (%s, %s, %s) values (?, ?, ?)',
                $this->wrapQueue($queue),
                $this->wrapIdentifier(self::MESSAGE_ID_COLUMN),
                $this->wrapIdentifier(self::PAYLOAD_COLUMN),
                $this->wrapIdentifier(self::DELIVER_TIME_COLUMN),
            ),
            [$messageId, $payload, $this->deliverTimeFor($delay)],
        );

        return $messageId;
    }

    /**
     * Turns a received message into a reserved job.
     *
     * @param string $queue
     * @param array<array-key, mixed> $message
     * @return SpannerJob|null null when the message was already taken by another worker
     */
    protected function reserve(string $queue, array $message): ?SpannerJob
    {
        if ($this->leaseHasExpired($message)) {
            // Another receiver may already hold this message. Leave it alone
            // and let Spanner redeliver it.
            return null;
        }

        $messageId = $message[self::MESSAGE_ID_COLUMN];
        $rawPayload = $message[self::PAYLOAD_COLUMN];
        assert(is_string($messageId) && is_string($rawPayload));

        $payload = $this->incrementAttempts($rawPayload);

        $reservedId = $this->connection->transaction(function () use ($queue, $messageId, $payload): ?string {
            if ($this->acknowledge($queue, $messageId) === 0) {
                // The message is already gone, so it was handled elsewhere.
                return null;
            }
            return $this->send($queue, $payload, $this->retryAfter);
        });

        return $reservedId !== null
            ? new SpannerJob($this->container, $this, $payload, $reservedId, $this->connectionName, $queue)
            : null;
    }

    /**
     * @param array<array-key, mixed> $message
     * @return bool
     */
    protected function leaseHasExpired(array $message): bool
    {
        $expiresAt = $message[self::LEASE_EXPIRATION_COLUMN] ?? null;

        return $expiresAt instanceof Timestamp
            && $expiresAt->get() < new DateTimeImmutable();
    }

    /**
     * Records that the payload is about to be handed to a worker.
     *
     * Every delivery goes through {@see self::reserve()}, so this stays
     * accurate even when a worker crashes without releasing the job.
     *
     * @param string $payload
     * @return string
     */
    protected function incrementAttempts(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return $payload;
        }

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
        );
    }

    /**
     * Opens a `RECEIVE_<queue>()` stream.
     *
     * The call must be a strong read, so it can neither join an open
     * read-write transaction nor run inside a snapshot.
     *
     * @param string $queue
     * @return Generator<int, array<array-key, mixed>>
     */
    protected function receive(string $queue): Generator
    {
        if ($this->connection->inTransaction() || $this->connection->inSnapshot()) {
            throw new LogicException(
                'Messages cannot be received inside a transaction or a snapshot, ' .
                'because Cloud Spanner rejects anything but a strong read.',
            );
        }

        $sql = sprintf(
            'select %s, %s, %s from %s(max_duration => %s)',
            $this->wrapIdentifier(self::MESSAGE_ID_COLUMN),
            $this->wrapIdentifier(self::PAYLOAD_COLUMN),
            $this->wrapIdentifier(self::LEASE_EXPIRATION_COLUMN),
            $this->receiveFunction($queue),
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
        $row = $this->connection->selectOne(sprintf(
            'select count(*) as `aggregate` from %s%s',
            $this->wrapQueue($queue),
            $operator !== null
                ? sprintf(
                    ' where %s %s current_timestamp()',
                    $this->wrapIdentifier(self::DELIVER_TIME_COLUMN),
                    $operator,
                )
                : '',
        ));

        $aggregate = is_array($row) ? ($row['aggregate'] ?? 0) : 0;

        return is_numeric($aggregate) ? (int) $aggregate : 0;
    }

    /**
     * @param DateTimeInterface|DateInterval|int $delay
     * @return DateTimeImmutable
     */
    protected function deliverTimeFor($delay): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->availableAt($delay));
    }

    /**
     * Name of the table valued function that receives from the given queue.
     *
     * @param string $queue
     * @return string
     */
    protected function receiveFunction(string $queue): string
    {
        return 'RECEIVE_' . $this->qualifyQueue($queue);
    }

    /**
     * @param string $queue
     * @return string
     */
    protected function wrapQueue(string $queue): string
    {
        return $this->wrapIdentifier($this->qualifyQueue($queue));
    }

    /**
     * Applies the connection's table prefix and rejects anything that is not a
     * plain Spanner identifier, since a queue name also has to be interpolated
     * into the `RECEIVE_<queue>()` function name, where it cannot be bound.
     *
     * @param string $queue
     * @return string
     */
    protected function qualifyQueue(string $queue): string
    {
        $name = $this->connection->getTablePrefix() . $queue;

        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $name) !== 1) {
            throw new InvalidArgumentException(
                "Queue name \"{$name}\" is not a valid Cloud Spanner identifier.",
            );
        }

        return $name;
    }

    /**
     * @param string $identifier
     * @return string
     */
    protected function wrapIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
