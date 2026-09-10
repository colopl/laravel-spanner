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

namespace Colopl\Spanner\Tests\Concerns;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Tests\TestCase;
use Google\Cloud\Spanner\V1\Session;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ManagesDataDefinitionsTest extends TestCase
{
    public function test_runDdlBatch(): void
    {
        $conn = $this->getDefaultConnection();
        $events = Event::fake([QueryExecuted::class]);
        $conn->setEventDispatcher($events);
        $conn->enableQueryLog();

        $newTable = 'runDdlBatch_' . Str::random(5);
        $statement = "create table {$newTable} (id int64) primary key (id)";
        $result = $conn->runDdlBatch([$statement]);
        $this->assertSame([], $result);
        $this->assertSame($statement, $conn->getQueryLog()[0]['query']);
        $this->assertCount(1, $conn->getQueryLog());
        $events->assertDispatchedTimes(QueryExecuted::class, 1);
        $this->assertContains($newTable, array_map(fn($d) => $d['name'], $conn->getSchemaBuilder()->getTables()));
    }

    public function test_runDdlBatch_within_pretend(): void
    {
        $events = Event::fake([QueryExecuted::class]);
        $conn = $this->getDefaultConnection();
        $conn->setEventDispatcher($events);
        $conn->enableQueryLog();

        $newTable = $this->generateTableName('runDdlBatch');
        $statement = "create table {$newTable} (id int64) primary key (id)";

        $result = null;
        $conn->pretend(function (Connection $conn) use (&$result, $statement) {
            $result = $conn->runDdlBatch([$statement]);
        });

        $this->assertSame([], $result);
        $this->assertSame([[
            'query' => $statement,
            'bindings' => [],
            'time' => 0.0,
            'readWriteType' => null,
        ]], $conn->getQueryLog());

        $events->assertDispatchedTimes(QueryExecuted::class, 1);

        $this->assertFalse($conn->getSchemaBuilder()->hasTable($newTable));
    }

    public function test_runDdlBatch_with_empty_statement(): void
    {
        $events = Event::fake([QueryExecuted::class]);
        $conn = $this->getDefaultConnection();
        $conn->setEventDispatcher($events);
        $conn->enableQueryLog();
        $result = $conn->runDdlBatch([]);

        $this->assertSame([], $result);
        $this->assertCount(0, $conn->getQueryLog());
        $events->assertNotDispatched(QueryExecuted::class);
    }

    public function test_createDatabase_with_statements(): void
    {
        $events = Event::fake([QueryExecuted::class]);
        $config = config('database.connections.main');
        $database = 'test_' . time();
        $sessionCache = new ArrayAdapter();
        $conn = new Connection($config['instance'], $database, '', $config, null, $sessionCache);

        if (!empty(getenv('SPANNER_EMULATOR_HOST'))) {
            $this->setUpEmulatorInstance($conn);
        }

        $conn->setEventDispatcher($events);
        $conn->enableQueryLog();

        $statements = array_map(
            static fn() => "create table " . 'createDatabase_' . md5(uniqid('', true)) . " (id int64) primary key (id)",
            range(0, 1),
        );

        $this->assertSame([], $sessionCache->getValues(), 'No session should exist before the database is created.');

        $conn->createDatabase($statements);

        $this->assertSame($statements[0], $conn->getQueryLog()[0]['query']);
        $this->assertSame($statements[1], $conn->getQueryLog()[1]['query']);
        $this->assertCount(2, $conn->getQueryLog());
        $events->assertDispatchedTimes(QueryExecuted::class, 2);

        // createDatabase() must refresh the session, so a session for the new database
        // must be created and cached without running any query.
        $cachedSessions = $sessionCache->getValues();
        $this->assertCount(1, $cachedSessions, 'Session must be refreshed right after the database is created.');
    }
}
