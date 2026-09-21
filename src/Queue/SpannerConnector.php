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
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Connectors\ConnectorInterface;
use InvalidArgumentException;

/**
 * @phpstan-type TQueueConfig array{
 *   driver: string,
 *   connection?: string|null,
 *   queue?: string,
 *   retry_after?: int,
 *   block_for?: int,
 *   after_commit?: bool|null,
 *   parent_keys?: list<string>,
 * }
 */
class SpannerConnector implements ConnectorInterface
{
    /**
     * @param ConnectionResolverInterface $connections
     */
    public function __construct(
        protected ConnectionResolverInterface $connections,
    ) {
    }

    /**
     * {@inheritDoc}
     * @param TQueueConfig $config
     * @return QueueContract
     */
    public function connect(array $config)
    {
        $name = $config['connection'] ?? null;
        $connection = $this->connections->connection($name);

        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException(sprintf(
                'Queue driver [spanner] requires a Cloud Spanner database connection, but [%s] is a %s.',
                $name ?? 'default',
                $connection::class,
            ));
        }

        return new SpannerQueue(
            $connection,
            $config['queue'] ?? 'default',
            (int) ($config['retry_after'] ?? 60),
            (int) ($config['block_for'] ?? 20),
            (bool) ($config['after_commit'] ?? false),
            $config['parent_keys'] ?? [],
        );
    }
}
