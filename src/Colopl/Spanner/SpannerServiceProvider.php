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

use Colopl\Spanner\Console\InstallCommand;
use Colopl\Spanner\Console\MigrateCommand;
use Colopl\Spanner\Console\ResetCommand;
use Colopl\Spanner\Console\RollbackCommand;
use Colopl\Spanner\Migrations\DatabaseMigrationRepository;
use Colopl\Spanner\Migrations\Migrator;
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

        $this->registerRepository();
        $this->registerMigrator();
        $this->registerCommands();

    }

    /**
     * @param array $config
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
     * @param  array   $config
     * @param  string  $name
     * @return array
     */
    protected function parseConfig(array $config, $name)
    {
        return Arr::add(Arr::add($config, 'prefix', ''), 'name', $name);
    }

    /**
     * @param array $sessionPoolConfig
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

    /**
     * Register the migration repository service.
     *
     * @return void
     */
    protected function registerRepository()
    {
        $this->app->singleton('spanner.migration.repository', function ($app) {
            $table = $app['config']['database.migrations'];
            return new DatabaseMigrationRepository($app['db'], $table);
        });
    }


    /**
     * Register the migrator service.
     *
     * @return void
     */
    protected function registerMigrator()
    {
        // The migrator is responsible for actually running and rollback the migration
        // files in the application. We'll pass in our database connection resolver
        // so the migrator can resolve any of these connections when it needs to.
        $this->app->singleton('spanner.migrator', function ($app) {
            $repository = $app['spanner.migration.repository'];
            return new Migrator($repository, $app['db'], $app['files']);
        });
    }

    /**
     * Register all of the Commands
     *
     * @return void
     */
    protected function registerCommands()
    {
        $this->registerMigrateCommand();
        $this->registerMigrateInstallCommand();
        $this->registerMigrateResetCommand();
        $this->registerMigrateRollbackCommand();
        $this->commands([
            'spanner.command.migrate',
            'spanner.command.migrate.install',
            'spanner.command.migrate.reset',
            'spanner.command.migrate.rollback',
        ]);
    }

    /**
     * Register the command.
     *
     * @return void
     */
    protected function registerMigrateCommand()
    {
        $this->app->singleton('spanner.command.migrate', function ($app) {
            return new MigrateCommand($app['spanner.migrator']);
        });
    }

    /**
     * Register the command.
     *
     * @return void
     */
    protected function registerMigrateInstallCommand()
    {
        $this->app->singleton('spanner.command.migrate.install', function ($app) {
            return new InstallCommand($app['spanner.migration.repository']);
        });
    }

    /**
     * Register the command.
     *
     * @return void
     */
    protected function registerMigrateResetCommand()
    {
        $this->app->singleton('spanner.command.migrate.reset', function ($app) {
            return new ResetCommand($app['spanner.migrator']);
        });
    }

    /**
     * Register the command.
     *
     * @return void
     */
    protected function registerMigrateRollbackCommand()
    {
        $this->app->singleton('spanner.command.migrate.rollback', function ($app) {
            return new RollbackCommand($app['spanner.migrator']);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'db.spanner',
            'spanner.migrator',
            'spanner.migration.repository',
            'spanner.command.migrate',
            'spanner.command.migrate.install',
            'spanner.command.migrate.reset',
            'spanner.command.migrate.rollback',
        ];
    }

}
