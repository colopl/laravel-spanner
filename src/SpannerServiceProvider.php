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
use Colopl\Spanner\Session\CacheSessionPool;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

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
        return new Connection(
            $config['instance'],
            $config['database'],
            $config['prefix'],
            $config,
            $this->createAuthCache(),
            $this->createSessionPool($config['session_pool'] ?? [])
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
     * @param array<string, mixed> $sessionPoolConfig
     * @return SessionPoolInterface
     */
    protected function createSessionPool(array $sessionPoolConfig): SessionPoolInterface
    {
        $cachePath = storage_path(implode(DIRECTORY_SEPARATOR, ['framework', 'spanner']));
        $adapter = new FilesystemAdapter('session', 0, $cachePath);
        return new CacheSessionPool($adapter, $sessionPoolConfig);
    }

    /**
     * @return CacheItemPoolInterface
     */
    protected function createAuthCache(): CacheItemPoolInterface
    {
        $cachePath = storage_path(implode(DIRECTORY_SEPARATOR, ['framework', 'spanner']));
        return new FilesystemAdapter('auth', 0, $cachePath);
    }

    protected function closeSessionAfterEachQueueJob(): void
    {
        $this->app->resolving('queue', function (QueueManager $queue) {
            $queue->after(static function () {
                foreach (DB::getConnections() as $connection) {
                    if ($connection instanceof Connection) {
                        $connection->disconnect();
                    }
                }
            });
        });
    }
}
