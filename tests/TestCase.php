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

use Closure;
use Colopl\Spanner\Connection;
use Colopl\Spanner\Schema\Blueprint;
use Colopl\Spanner\SpannerServiceProvider;
use Google\Cloud\Spanner\Bytes;
use Google\Cloud\Spanner\Date;
use Google\Cloud\Spanner\Numeric;
use Google\Cloud\Spanner\SpannerClient;
use Illuminate\Foundation\Application;
use Ramsey\Uuid\Uuid;

/**
 * @property Application $app
 */
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected const TABLE_NAME_TEST = 'Test';
    protected const TABLE_NAME_USER = 'User';
    protected const TABLE_NAME_USER_INFO = 'UserInfo';
    protected const TABLE_NAME_USER_ITEM = 'UserItem';
    protected const TABLE_NAME_ITEM = 'Item';
    protected const TABLE_NAME_TAG = 'Tag';
    protected const TABLE_NAME_ITEM_TAG = 'ItemTag';
    protected const TABLE_NAME_ARRAY_TEST = 'ArrayTest';

    /**
     * @param Closure(Blueprint): void $callback
     */
    protected function createTempTable(Closure $callback): string
    {
        $table = $this->generateTableName('Test_' . date('Ymd_His_v'));

        $this->getDefaultConnection()
            ->getSchemaBuilder()
            ->create($table, $callback);

        return $table;
    }

    protected function generateTableName(string $prefix): string
    {
        return $prefix . '_' . date('Ymd_His_v');
    }

    /**
     * @return string
     */
    protected function generateUuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * @return array<string, mixed>
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
            'numericTest' => new Numeric('123.456'),
            'nullableNumericTest' => null,
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
        $this->setUpDatabaseOnce($conn);
        return $conn;
    }

    /**
     * @return Connection
     */
    protected function getAlternativeConnection(): Connection
    {
        /** @var Connection $conn */
        $conn = $this->getConnection('alternative');
        $this->setUpDatabaseOnce($conn);
        return $conn;
    }

    /**
     * @param Connection $conn
     * @return void
     */
    protected function setUpEmulatorInstance(Connection $conn): void
    {
        $spanner = new SpannerClient((array)$conn->getConfig('client'));
        $name = (string)$conn->getConfig('instance');
        if (! $spanner->instance($name)->exists()) {
            $config = $spanner->instanceConfiguration('emulator-config');
            $spanner->createInstance($config, $name)->pollUntilComplete();
            logger()?->debug('Created Spanner Emulator Instance: ' . $name);
        }
    }

    /**
     * @param Connection $conn
     * @return void
     */
    protected function setUpDatabaseOnce(Connection $conn): void
    {
        if (!empty(getenv('SPANNER_EMULATOR_HOST'))) {
            $this->setUpEmulatorInstance($conn);
        }
        if (!$conn->databaseExists()) {
            $conn->createDatabase($this->getTestDatabaseDDLs());
        }
        $this->beforeApplicationDestroyed(fn () => $this->cleanupDatabase($conn));
    }

    /**
     * @param Connection $conn
     * @return void
     */
    protected function cleanupDatabase(Connection $conn): void
    {
        foreach ($conn->select("SELECT t.table_name FROM information_schema.tables as t WHERE t.table_schema = ''") as $row) {
            $conn->table($row['table_name'])->truncate();
        }
        $conn->clearSessionPool();
    }

    /**
     * @return list<string>
     */
    protected function getTestDatabaseDDLs(): array
    {
        $ddlFile = __DIR__ . '/test.ddl';
        return collect(explode(';', file_get_contents($ddlFile) ?: ''))
            ->map(function($ddl) { return trim($ddl); })
            ->filter()
            ->all();
    }

    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SpannerServiceProvider::class];
    }

    /**
     * @param Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        $dbConfig = require __DIR__ . '/config.php';
        $app['config']->set('database', $dbConfig);
    }
}
