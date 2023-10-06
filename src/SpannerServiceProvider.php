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

namespace Colopl\Spanner;

use Colopl\Spanner\Console\CooldownCommand;
use Colopl\Spanner\Console\SessionsCommand;
use Colopl\Spanner\Console\WarmupCommand;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class SpannerServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->resolving('db', function (DatabaseManager $db) {
            $db->extend('spanner', function (array $config, string $name): Connection {
                return $this->createSpannerConnection($this->parseConfig($config, $name));
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CooldownCommand::class,
                SessionsCommand::class,
                WarmupCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->closeSessionAfterEachQueueJob();
    }

    /**
     * @param array $config
     * @return Connection
     */
    protected function createSpannerConnection(array $config): Connection
    {
        $cache = $this->getCacheAdapter();

        return new Connection(
            $config['instance'],
            $config['database'],
            $config['prefix'],
            $config,
            $cache,
            new CacheSessionPool($cache, $config['session_pool'] ?? [])
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param string $name
     * @return array<string, mixed>
     */
    protected function parseConfig(array $config, string $name): array
    {
        return $config + [
            'prefix' => '',
            'name' => $name,
            'useGapicBackoffs' => true,
        ];
    }

    /**
     * @return AdapterInterface
     */
    protected function getCacheAdapter(): AdapterInterface
    {
        $path = $this->app->storagePath('framework');
        return new FileCacheAdapter('spanner', $path);
    }

    protected function closeSessionAfterEachQueueJob(): void
    {
        $this->app->resolving('queue', function (QueueManager $queue): void {
            $queue->after(static function (): void {
                foreach (DB::getConnections() as $connection) {
                    if ($connection instanceof Connection) {
                        $connection->disconnect();
                    }
                }
            });
        });
    }
}
