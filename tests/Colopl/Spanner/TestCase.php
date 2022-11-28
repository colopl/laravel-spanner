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

namespace Colopl\Spanner\Tests;

use Colopl\Spanner\Connection;
use Colopl\Spanner\SpannerServiceProvider;
use Google\Cloud\Spanner\Bytes;
use Google\Cloud\Spanner\Date;
use Google\Cloud\Spanner\SpannerClient;
use Ramsey\Uuid\Uuid;

class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Override me if you need to prepare test tables.
     * @var bool
     */
    protected const TEST_DB_REQUIRED = false;

    /**
     * @var bool
     */
    protected static bool $databasePrepared = false;

    protected const TABLE_NAME_TEST = 'Test';
    protected const TABLE_NAME_USER = 'User';
    protected const TABLE_NAME_USER_INFO = 'UserInfo';
    protected const TABLE_NAME_USER_ITEM = 'UserItem';
    protected const TABLE_NAME_ITEM = 'Item';
    protected const TABLE_NAME_TAG = 'Tag';
    protected const TABLE_NAME_ITEM_TAG = 'ItemTag';
    protected const TABLE_NAME_ARRAY_TEST = 'ArrayTest';

    protected function tearDown(): void
    {
        if (static::TEST_DB_REQUIRED) {
            $this->cleanupDatabaseRecords();
        }
        parent::tearDown();
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function generateUuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * @return array
     * @throws \Exception
     */
    protected function generateTestRow(): array
    {
        return [
            'testId' => $this->generateUuid(),
            'uniqueStringTest' => $this->generateUuid(),
            'stringTest' => 'test',
            'nullableStringTest' => null,
            'intTest' => 123456,
            'nullableIntTest' => null,
            'floatTest' => 123.456,
            'nullableFloatTest' => null,
            'timestampTest' => new \DateTimeImmutable(),
            'nullableTimestampTest' => null,
            'dateTest' => new Date(new \DateTimeImmutable()),
            'nullableDateTest' => null,
            'bytesTest' => new Bytes("\x00\x01\x02"),
            'nullableBytesTest' => null,
        ];
    }

    /**
     * @return Connection
     */
    protected function getDefaultConnection(): Connection
    {
        /** @var Connection $conn */
        $conn = $this->getConnection();
        if (static::TEST_DB_REQUIRED) {
            $this->setUpDatabaseOnce($conn);
        }
        return $conn;
    }

    /**
     * @return Connection
     */
    protected function getAlternativeConnection(): Connection
    {
        /** @var Connection $conn */
        $conn = $this->getConnection('alternative');
        if (static::TEST_DB_REQUIRED) {
            $this->setUpDatabaseOnce($conn);
        }
        return $conn;
    }

    protected function setUpDatabaseOnce(Connection $conn): void
    {
        if (!self::$databasePrepared) {
            self::$databasePrepared = true;
            $this->setUpDatabase($conn);
        }
    }

    protected function setUpDatabase(Connection $conn): void
    {
        if (!empty(getenv('SPANNER_EMULATOR_HOST'))) {
            $this->createEmulatorInstance($conn);
        }

        if ($conn->databaseExists()) {
            $conn->dropDatabase();
        }
        $conn->createDatabase($this->getTestDatabaseDDLs());
        $conn->clearSessionPool();
    }

    protected function createEmulatorInstance(Connection $conn): void
    {
        $spanner = new SpannerClient((array)$conn->getConfig('client'));
        $name = (string)$conn->getConfig('instance');
        if (! $spanner->instance($name)->exists()) {
            $config = $spanner->instanceConfiguration('emulator-config');
            $spanner->createInstance($config, $name)->pollUntilComplete();
            logger()?->debug('Created Spanner Emulator Instance: ' . $name);
        }
    }

    protected function cleanupDatabaseRecords(): void
    {
        /** @var Connection $conn */
        foreach ($this->app['db']->getConnections() as $conn) {
            foreach ($conn->select("SELECT t.table_name FROM information_schema.tables as t WHERE t.table_schema = ''") as $row) {
                $tableName = $row['table_name'];
                $conn->runPartitionedDml("DELETE FROM `${tableName}` WHERE TRUE");
            }
        }
    }

    /**
     * @return string[]
     */
    protected function getTestDatabaseDDLs(): array
    {
        $ddlFile = __DIR__.'/test.ddl';
        return collect(explode(';', file_get_contents($ddlFile) ?: ''))
            ->map(function($ddl) { return trim($ddl); })
            ->filter()
            ->all();
    }

    protected function getPackageProviders($app): array
    {
        return [SpannerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $dbConfig = require __DIR__.'/config.php';
        $app['config']->set('database', $dbConfig);
    }
}
