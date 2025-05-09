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
use Colopl\Spanner\Schema\Blueprint;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * @phpstan-type TConfig array{
 *   name: string,
 *   instance: string,
 *   database: string,
 *   prefix: string,
 *   cache_path: string|null,
 *   session_pool: array<string, mixed>,
 * }
 */
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

        $this->app->bind(
            BaseBlueprint::class,
            static fn ($app, array $parameters = []) => new Blueprint(...$parameters),
        );

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
     * @param TConfig $config
     * @return Connection
     */
    protected function createSpannerConnection(array $config): Connection
    {
        return new Connection(
            $config['instance'],
            $config['database'],
            $config['prefix'],
            $config,
            $this->createAuthCache($config),
            $this->createSessionPool($config),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return TConfig
     */
    protected function parseConfig(array $config, string $name): array
    {
        if ($name === '_auth') {
            throw new LogicException('Connection name "_auth" is reserved.');
        }

        /**
         * @var TConfig
         */
        return $config + [
            'prefix' => '',
            'name' => $name,
            'cache_path' => null,
            'session_pool' => [],
        ];
    }

    /**
     * @param array{ name: string, cache_path: string|null } $config
     * @return AdapterInterface
     */
    protected function createAuthCache(array $config): AdapterInterface
    {
        return $this->getCacheAdapter($config['name'] . '_auth', $config['cache_path']);
    }

    /**
     * @param array{ name: string, cache_path: string|null, session_pool: array<string, mixed> } $config
     * @return SessionPoolInterface
     */
    protected function createSessionPool(array $config): SessionPoolInterface
    {
        return new CacheSessionPool(
            $this->getCacheAdapter($config['name'] . '_sessions', $config['cache_path']),
            $config['session_pool'],
        );
    }

    /**
     * @param string $namespace
     * @param string|null $path
     * @return AdapterInterface
     */
    protected function getCacheAdapter(string $namespace, ?string $path): AdapterInterface
    {
        $path ??= $this->app->storagePath('framework/spanner');
        return new FilesystemAdapter($namespace, 0, $path);
    }

    /**
     * @return void
     */
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
