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

use Closure;
use Generator;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Holds the long running `RECEIVE_<queue>()` stream open across calls.
 *
 * Spanner expects one receiving query per worker per queue, kept open in a
 * loop, so the stream is deliberately *not* re-opened for every message. The
 * underlying generator is only advanced right before the next message is
 * requested, because advancing it blocks until Spanner pushes the next message
 * (or the stream reaches `max_duration`).
 *
 * A stream only lives for its `max_duration`, and the caller is not necessarily
 * around when it ends, since a job can easily run for longer than `block_for`.
 * Asking a stream that has already ended for the next message would report an
 * empty queue instantly, so the worker would go to sleep with work waiting.
 * Such a stream is therefore replaced rather than consulted.
 *
 * @see https://cloud.google.com/spanner/docs/queues/queues-using#receive-messages
 * @internal
 */
class MessageReceiver
{
    /**
     * @var Generator<int, array<array-key, mixed>>|null
     */
    protected ?Generator $stream = null;

    /**
     * Whether the message currently pointed at was already handed out.
     */
    protected bool $consumed = false;

    /**
     * When the held stream reaches its `max_duration`, as a unix timestamp.
     */
    protected float $expiresAt = 0.0;

    /**
     * @param Closure(): Generator<int, array<array-key, mixed>> $factory
     * @param int $lifetime seconds a stream stays open, which is its `max_duration`
     */
    public function __construct(
        protected Closure $factory,
        protected int $lifetime,
    ) {
    }

    /**
     * Blocks until the next message arrives, or until the stream is exhausted.
     *
     * @return array<array-key, mixed>|null null once a stream opened for this call reached its `max_duration`.
     */
    public function next(): ?array
    {
        if ($this->stream !== null && $this->now() >= $this->expiresAt) {
            $this->close();
        }

        if ($this->stream === null) {
            $this->expiresAt = $this->now() + $this->lifetime;
            $this->stream = ($this->factory)();
        }
        $stream = $this->stream;

        try {
            if ($this->consumed) {
                $this->consumed = false;
                $stream->next();
            }

            if (!$stream->valid()) {
                $this->close();
                return null;
            }

            $this->consumed = true;
            return $stream->current();
        } catch (Throwable $e) {
            // The stream cannot be resumed once it errors, so drop it and let
            // the next call start a fresh one.
            $this->close();
            throw $e;
        }
    }

    /**
     * Discards the stream. A subsequent {@see self::next()} opens a new one.
     *
     * @return void
     */
    public function close(): void
    {
        $this->stream = null;
        $this->consumed = false;
    }

    /**
     * @return float
     */
    protected function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }
}
