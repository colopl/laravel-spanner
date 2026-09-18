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

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job as BaseJob;

class SpannerJob extends BaseJob implements JobContract
{
    /**
     * @param Container $container
     * @param SpannerQueue $spannerQueue
     * @param string $payload raw payload of the reserved message
     * @param string $messageId identifier of the reserved message
     * @param string $connectionName
     * @param string $queue
     */
    public function __construct(
        Container $container,
        protected SpannerQueue $spannerQueue,
        protected string $payload,
        protected string $messageId,
        string $connectionName,
        string $queue,
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    /**
     * @inheritDoc
     */
    public function getJobId()
    {
        return $this->messageId;
    }

    /**
     * @inheritDoc
     */
    public function getRawBody()
    {
        return $this->payload;
    }

    /**
     * @inheritDoc
     */
    public function attempts()
    {
        $payload = json_decode($this->payload, true);

        $attempts = is_array($payload)
            ? ($payload['attempts'] ?? 0)
            : 0;
        $attempts = is_int($attempts) ? $attempts : 0;

        // The message was already handed out when this job was created, so it
        // has been attempted at least once.
        return max($attempts, 1);
    }

    /**
     * @inheritDoc
     */
    public function delete()
    {
        parent::delete();

        $this->spannerQueue->acknowledge($this->queue, $this->messageId);
    }

    /**
     * @inheritDoc
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $this->spannerQueue->deleteAndRelease($this->queue, $this, $delay);
    }
}
