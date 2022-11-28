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

use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Psr\Cache\CacheItemPoolInterface;

class SpannerServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register()
    {
        $this->app->resolving('db', function (DatabaseManager $db) {
            $db->extend('spanner', function ($config, $name) {
                $config = $this->parseConfig($config, $name);
                return $this->createSpannerConnection($name, $config);
            });
        });
    }

    /**
     * @param string $name
     * @param array $config
     * @return Connection
     */
    protected function createSpannerConnection(string $name, array $config)
    {
        return new Connection(
            $config['instance'],
            $config['database'],
            $config['prefix'],
            $config,
            $this->createAuthCache($name),
            $this->createSessionPool($name, $config['session_pool'] ?? [])
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param string $name
     * @return array<string, mixed>
     */
    protected function parseConfig(array $config, $name)
    {
        return $config + [
            'prefix' => '',
            'name' => $name,
            'useGapicBackoffs' => true,
        ];
    }

    /**
     * @param string $name
     * @param array|null $config
     * @return SessionPoolInterface
     */
    protected function createSessionPool(string $name, ?array $config): SessionPoolInterface
    {
        $cachePath = storage_path(implode(DIRECTORY_SEPARATOR, ['framework', 'cache', 'spanner']));
        $cacheAdapter = new FileCacheAdapter("{$name}-session", $cachePath);
        return new CacheSessionPool($cacheAdapter, $config ?? []);
    }

    /**
     * @param string $name
     * @return CacheItemPoolInterface
     */
    protected function createAuthCache(string $name): CacheItemPoolInterface
    {
        $cachePath = storage_path(implode(DIRECTORY_SEPARATOR, ['framework', 'cache', 'spanner']));
        return new FileCacheAdapter('auth', $cachePath);
    }

}
