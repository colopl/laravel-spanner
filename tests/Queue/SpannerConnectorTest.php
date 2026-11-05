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

use Colopl\Spanner\Queue\SpannerQueue;
use Colopl\Spanner\Tests\TestCase;
use Illuminate\Queue\QueueManager;
use InvalidArgumentException;

class SpannerConnectorTest extends TestCase
{
    public function test_service_provider_registers_the_driver(): void
    {
        config()->set('queue.connections.spanner', [
            'driver' => 'spanner',
            'connection' => 'main',
            'queue' => 'Jobs',
            'retry_after' => 90,
            'block_for' => 5,
        ]);

        /** @var QueueManager $manager */
        $manager = $this->app->make('queue');
        $queue = $manager->connection('spanner');

        $this->assertInstanceOf(SpannerQueue::class, $queue);
        $this->assertSame('spanner', $queue->getConnectionName());
        $this->assertSame('Jobs', $queue->getQueue(null));
        $this->assertSame('main', $queue->getConnection()->getName());
    }

    public function test_driver_rejects_a_non_spanner_database_connection(): void
    {
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        config()->set('queue.connections.not-spanner', [
            'driver' => 'spanner',
            'connection' => 'sqlite',
            'queue' => 'Jobs',
        ]);

        /** @var QueueManager $manager */
        $manager = $this->app->make('queue');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a Cloud Spanner database connection');

        $manager->connection('not-spanner');
    }
}
