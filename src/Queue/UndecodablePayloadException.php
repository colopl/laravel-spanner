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

use Illuminate\Queue\InvalidPayloadException;

/**
 * Thrown by {@see SpannerQueue::pop()} for a received message whose payload is
 * not a JSON object. The message has already been deleted by then, since no
 * worker could ever run it.
 *
 * Laravel's worker reports it to the exception handler, which adds
 * {@see self::context()} to the log entry, so the payload is not lost with the
 * message.
 */
class UndecodablePayloadException extends InvalidPayloadException
{
    /**
     * @param string $message
     * @param string $queue
     * @param array<string, scalar> $key full primary key of the deleted message
     * @param string $payload
     */
    public function __construct(
        string $message,
        public readonly string $queue,
        public readonly array $key,
        string $payload,
    ) {
        parent::__construct($message, $payload);
    }

    /**
     * Merged into the log context by Laravel's exception handler.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return ['queue' => $this->queue, 'key' => $this->key, 'payload' => $this->value];
    }
}
