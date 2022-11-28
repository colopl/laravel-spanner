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
use Colopl\Spanner\Events\MutatingData;
use Colopl\Spanner\Session;
use Colopl\Spanner\TimestampBound\ExactStaleness;
use Colopl\Spanner\TimestampBound\MaxStaleness;
use Colopl\Spanner\TimestampBound\MinReadTimestamp;
use Colopl\Spanner\TimestampBound\ReadTimestamp;
use Colopl\Spanner\TimestampBound\StrongRead;
use Google\Auth\FetchAuthTokenInterface;
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Carbon;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ConnectionTest extends TestCase
{
    protected const TEST_DB_REQUIRED = true;

    public function testConnect(): void
    {
        $conn = $this->getDefaultConnection();
        $this->assertInstanceOf(Connection::class, $conn);
        $this->assertNotEmpty($conn->getName());
        $conn->disconnect();
    }

    public function testReconnect(): void
    {
        $conn = $this->getDefaultConnection();
        $this->assertInstanceOf(Connection::class, $conn);
        $conn->reconnect();
        $this->assertEquals(12345, $conn->selectOne('SELECT 12345')[0]);
    }

    public function testQueryLog(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->enableQueryLog();

        $conn->select('SELECT 1');
        $this->assertCount(1, $conn->getQueryLog());

        $conn->select('SELECT 2');
        $this->assertCount(2, $conn->getQueryLog());
    }

    public function testInsertUsingMutationWithTransaction(): void
    {
        $userId = $this->generateUuid();
        $transactionBeginCount = 0;
        $transactionCommitCount = 0;
        $mutatingDataCount = 0;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$transactionBeginCount) { $transactionBeginCount++; });
        $this->app['events']->listen(MutatingData::class, function () use (&$transactionCommitCount) { $transactionCommitCount++; });
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$mutatingDataCount) { $mutatingDataCount++; });

        $conn = $this->getDefaultConnection();
        $conn->transaction(function () use ($conn, $userId) {
            $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        });

        $this->assertCount(1, $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->get());
        $this->assertEquals(1, $transactionBeginCount);
        $this->assertEquals(1, $mutatingDataCount);
        $this->assertEquals(1, $transactionCommitCount);
    }

    public function testInsertUsingMutationWithoutTransaction(): void
    {
        $userId = $this->generateUuid();
        $transactionBeginCount = 0;
        $transactionCommitCount = 0;
        $mutatingDataCount = 0;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$transactionBeginCount) { $transactionBeginCount++; });
        $this->app['events']->listen(MutatingData::class, function () use (&$transactionCommitCount) { $transactionCommitCount++; });
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$mutatingDataCount) { $mutatingDataCount++; });

        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);

        $this->assertCount(1, $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->get());
        $this->assertEquals(1, $transactionBeginCount);
        $this->assertEquals(1, $mutatingDataCount);
        $this->assertEquals(1, $transactionCommitCount);
    }

    public function testUpdateUsingMutationWithTransaction(): void
    {
        $userId = $this->generateUuid();
        $transactionBeginCount = 0;
        $transactionCommitCount = 0;
        $mutatingDataCount = 0;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$transactionBeginCount) { $transactionBeginCount++; });
        $this->app['events']->listen(MutatingData::class, function () use (&$transactionCommitCount) { $transactionCommitCount++; });
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$mutatingDataCount) { $mutatingDataCount++; });

        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->transaction(function () use ($conn, $userId) {
            $conn->updateUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'tester']);
        });

        $this->assertEquals(['userId' => $userId, 'name' => 'tester'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        $this->assertEquals(2, $transactionBeginCount);
        $this->assertEquals(2, $mutatingDataCount);
        $this->assertEquals(2, $transactionCommitCount);
    }

    public function testUpdateUsingMutationWithoutTransaction(): void
    {
        $userId = $this->generateUuid();
        $transactionBeginCount = 0;
        $transactionCommitCount = 0;
        $mutatingDataCount = 0;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$transactionBeginCount) { $transactionBeginCount++; });
        $this->app['events']->listen(MutatingData::class, function () use (&$transactionCommitCount) { $transactionCommitCount++; });
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$mutatingDataCount) { $mutatingDataCount++; });

        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->updateUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'tester']);

        $this->assertEquals(['userId' => $userId, 'name' => 'tester'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        $this->assertEquals(2, $transactionBeginCount);
        $this->assertEquals(2, $transactionCommitCount);
        $this->assertEquals(2, $mutatingDataCount);
    }

    public function testDeleteUsingMutationWithTransaction(): void
    {
        $userId = $this->generateUuid();
        $transactionBeginCount = 0;
        $transactionCommitCount = 0;
        $mutatingDataCount = 0;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$transactionBeginCount) { $transactionBeginCount++; });
        $this->app['events']->listen(MutatingData::class, function () use (&$transactionCommitCount) { $transactionCommitCount++; });
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$mutatingDataCount) { $mutatingDataCount++; });

        $conn = $this->getDefaultConnection();

        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->transaction(function () use ($conn, $userId) {
            $conn->deleteUsingMutation(self::TABLE_NAME_USER, $userId);
        });

        $this->assertNull($conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        $this->assertEquals(2, $transactionBeginCount);
        $this->assertEquals(2, $transactionCommitCount);
        $this->assertEquals(2, $mutatingDataCount);
    }

    public function testDeleteUsingMutationWithoutTransaction(): void
    {
        $userId = $this->generateUuid();
        $transactionBeginCount = 0;
        $transactionCommitCount = 0;
        $mutatingDataCount = 0;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$transactionBeginCount) { $transactionBeginCount++; });
        $this->app['events']->listen(MutatingData::class, function () use (&$transactionCommitCount) { $transactionCommitCount++; });
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$mutatingDataCount) { $mutatingDataCount++; });

        $conn = $this->getDefaultConnection();

        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->deleteUsingMutation(self::TABLE_NAME_USER, $userId);

        $this->assertNull($conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        $this->assertEquals(2, $transactionBeginCount);
        $this->assertEquals(2, $transactionCommitCount);
        $this->assertEquals(2, $mutatingDataCount);
    }

    public function testDeleteUsingMutationWithDifferentArgs(): void
    {
        $conn = $this->getDefaultConnection();
        $userIds = collect(range(0, 4))->map(function() { return $this->generateUuid(); });
        $conn->transaction(function() use ($conn, $userIds) {
            $dataSet = $userIds->map(function($userId) { return ['userId' => $userId, 'name' => 'test']; })->all();
            $conn->insertUsingMutation(self::TABLE_NAME_USER, $dataSet);
        });

        $targetUserId = $userIds->splice(0, 1)->first();
        $conn->deleteUsingMutation(self::TABLE_NAME_USER, $targetUserId);
        $this->assertEmpty($conn->table(self::TABLE_NAME_USER)->where('userId', $targetUserId)->get());

        $targetUserIds = $userIds->splice(0, 2)->all();
        $conn->deleteUsingMutation(self::TABLE_NAME_USER, $targetUserIds);
        $this->assertEmpty($conn->table(self::TABLE_NAME_USER)->whereIn('userId', $targetUserIds)->get());

        $targetUserIds = $userIds->splice(0, 2)->all();
        $conn->deleteUsingMutation(self::TABLE_NAME_USER, new KeySet(['keys' => $targetUserIds]));
        $this->assertEmpty($conn->table(self::TABLE_NAME_USER)->whereIn('userId', $targetUserIds)->get());
    }

    public function testQueryExecutedEvent(): void
    {
        $conn = $this->getDefaultConnection();

        $executedCount = 0;
        $this->app['events']->listen(QueryExecuted::class, function () use (&$executedCount) {
            $executedCount++;
        });

        $conn->selectOne('SELECT 1');
        $conn->select('SELECT 1');
        $tableName = self::TABLE_NAME_USER;
        $uuid = $this->generateUuid();
        $name = 'test';
        $conn->insert("INSERT INTO ${tableName} (`userId`, `name`) VALUES ('${uuid}', '${name}')");
        $afterName = 'test2';
        $conn->update("UPDATE ${tableName} SET `name` = '${afterName}' WHERE `userId` = '${uuid}'");
        $conn->delete("DELETE FROM ${tableName} WHERE `userId` = '${uuid}'");

        $this->assertEquals(5, $executedCount);
    }

    public function testSession(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->disconnect();

        $this->assertNull($conn->__debugInfo()['session'], 'At the time of creating the connection, the session has not been created yet.');

        $conn->selectOne('SELECT 1');

        $this->assertNotEmpty($conn->__debugInfo()['session'], 'After executing some query, session is created.');
    }

    public function testCredentialFetcher(): void
    {
        if (getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot test credential fetcher on emulator');
        }

        $conn = $this->getDefaultConnection();
        /** @var FetchAuthTokenInterface|null $credentialFetcher */
        $credentialFetcher = $conn->__debugInfo()['credentialFetcher'];

        $this->assertInstanceOf(FetchAuthTokenInterface::class, $credentialFetcher);
        $this->assertNotEmpty($credentialFetcher->getCacheKey());
    }

    public function testAuthCache(): void
    {
        if (getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot test AuthCache on emulator');
        }

        $config = $this->app['config']->get('database.connections.main');

        $authCache = new ArrayAdapter();
        $conn = new Connection($config['instance'], $config['database'], '', $config, $authCache);
        $this->assertInstanceOf(Connection::class, $conn);

        $conn->selectOne('SELECT 1');
        $this->assertNotEmpty($authCache->getValues(), 'After executing some query, session cache is created.');
    }

    public function testSessionPool(): void
    {
        $config = $this->app['config']->get('database.connections.main');

        $cacheItemPool = new ArrayAdapter();
        $cacheSessionPool = new CacheSessionPool($cacheItemPool);
        $conn = new Connection($config['instance'], $config['database'], '', $config, null, $cacheSessionPool);
        $this->assertInstanceOf(Connection::class, $conn);

        $conn->selectOne('SELECT 1');
        $this->assertNotEmpty($cacheItemPool->getValues(), 'After executing some query, cache is created.');

        $conn->clearSessionPool();
        $this->assertEmpty($cacheItemPool->getValues(), 'After clearing the session pool, cache is removed.');
    }

    public function testListSessions(): void
    {
        if (getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot list sessions on emulator');
        }

        $conn = $this->getDefaultConnection();

        $sessions = $conn->listSessions();
        $this->assertNotEmpty($sessions);
        $this->assertInstanceOf(Session::class, $sessions[0]);
    }

    public function testStaleReads(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;
        $uuid = $this->generateUuid();

        $db = (new SpannerClient())->connect(config('database.connections.main.instance'), config('database.connections.main.database'));
        /** @var Timestamp|null $timestamp */
        $timestamp = null;
        $db->runTransaction(function(Transaction $tx) use ($tableName, $uuid, &$timestamp) {
            $name = 'first';
            $tx->executeUpdate("INSERT INTO ${tableName} (`userId`, `name`) VALUES ('${uuid}', '${name}')");
            $timestamp = $tx->commit();
        });
        $this->assertNotEmpty($timestamp);

        $timestampBound = new StrongRead();
        $row = $conn->selectOneWithTimestampBound("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound);
        $this->assertNotEmpty($row);
        $this->assertEquals($uuid, $row['userId']);
        $this->assertEquals('first', $row['name']);

        $oldDatetime = Carbon::instance($timestamp->get())->subSecond();

        $timestampBound = new ReadTimestamp($oldDatetime);
        $row = $conn->selectOneWithTimestampBound("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound);
        $this->assertEmpty($row);

        $timestampBound = new ExactStaleness(10);
        $row = $conn->selectOneWithTimestampBound("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound);
        $this->assertEmpty($row);

        $timestampBound = new MaxStaleness(10);
        $row = $conn->selectOneWithTimestampBound("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound);
        $this->assertNotEmpty($row);
        $this->assertEquals($uuid, $row['userId']);
        $this->assertEquals('first', $row['name']);

        $timestampBound = new MinReadTimestamp($oldDatetime);
        $row = $conn->selectOneWithTimestampBound("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound);
        $this->assertNotEmpty($row);
        $this->assertEquals($uuid, $row['userId']);
        $this->assertEquals('first', $row['name']);
    }

    public function testEventListenOrder(): void
    {
        $receivedEventClasses = [];
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$receivedEventClasses) { $receivedEventClasses[] = TransactionBeginning::class; });
        $this->app['events']->listen(QueryExecuted::class, function () use (&$receivedEventClasses) { $receivedEventClasses[] = QueryExecuted::class; });
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$receivedEventClasses) { $receivedEventClasses[] = TransactionCommitted::class; });

        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $uuid = $this->generateUuid();
        $name = 'test';
        $conn->insert("INSERT INTO ${tableName} (`userId`, `name`) VALUES ('${uuid}', '${name}')");

        $this->assertCount(3, $receivedEventClasses);
        $this->assertEquals(TransactionBeginning::class, $receivedEventClasses[0]);
        $this->assertEquals(QueryExecuted::class, $receivedEventClasses[1]);
        $this->assertEquals(TransactionCommitted::class, $receivedEventClasses[2]);
    }
}
