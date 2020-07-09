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

use Exception;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
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
                return $this->createSpannerConnection($this->parseConfig($config, $name));
            });
        });
    }

    /**
     * @param array<mixed> $config
     * @return Connection
     * @throws Exception
     */
    protected function createSpannerConnection(array $config)
    {
        $authCache = $this->createAuthCache();
        $sessionPool = $this->createSessionPool($config['session_pool'] ?? []);
        return new Connection($config['instance'], $config['database'], $config['prefix'], $config, $authCache, $sessionPool);
    }

    /**
     * @param  array<mixed> $config
     * @param  string $name
     * @return array<mixed>
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
     * @param array<int> $sessionPoolConfig
     * @return SessionPoolInterface
     * @throws Exception
     */
    protected function createSessionPool(array $sessionPoolConfig): SessionPoolInterface
    {
        $cachePath = storage_path(implode(DIRECTORY_SEPARATOR, ['framework', 'cache', 'spanner']));
        return new CacheSessionPool(new FileCacheAdapter('session', $cachePath), $sessionPoolConfig);
    }

    /**
     * @return CacheItemPoolInterface
     * @throws Exception
     */
    protected function createAuthCache(): CacheItemPoolInterface
    {
        $cachePath = storage_path(implode(DIRECTORY_SEPARATOR, ['framework', 'cache', 'spanner']));
        return new FileCacheAdapter('auth', $cachePath);
    }

}
