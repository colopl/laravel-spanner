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
use Colopl\Spanner\Session\SessionInfo;
use Colopl\Spanner\TimestampBound\ExactStaleness;
use Colopl\Spanner\TimestampBound\MaxStaleness;
use Colopl\Spanner\TimestampBound\MinReadTimestamp;
use Colopl\Spanner\TimestampBound\ReadTimestamp;
use Colopl\Spanner\TimestampBound\StrongRead;
use Google\Auth\FetchAuthTokenInterface;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ConnectionTest extends TestCase
{
    public function testConnect(): void
    {
        $conn = $this->getDefaultConnection();
        $this->assertNotEmpty($conn->getName());
        $conn->disconnect();
    }

    public function testReconnect(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->reconnect();
        $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
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
        Event::fake();

        $userId = $this->generateUuid();
        $conn = $this->getDefaultConnection();
        $conn->transaction(function () use ($conn, $userId) {
            $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        });

        $this->assertCount(1, $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->get());
        Event::assertDispatchedTimes(TransactionBeginning::class);
        Event::assertDispatchedTimes(MutatingData::class);
        Event::assertDispatchedTimes(TransactionCommitted::class);
    }

    public function testInsertUsingMutationWithoutTransaction(): void
    {
        Event::fake();

        $userId = $this->generateUuid();
        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);

        $this->assertCount(1, $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->get());
        Event::assertDispatchedTimes(TransactionBeginning::class);
        Event::assertDispatchedTimes(MutatingData::class);
        Event::assertDispatchedTimes(TransactionCommitted::class);
    }

    public function testUpdateUsingMutationWithTransaction(): void
    {
        Event::fake();

        $userId = $this->generateUuid();
        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->transaction(function () use ($conn, $userId) {
            $conn->updateUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'tester']);
        });

        $this->assertEquals(['userId' => $userId, 'name' => 'tester'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        Event::assertDispatchedTimes(TransactionBeginning::class, 2);
        Event::assertDispatchedTimes(MutatingData::class, 2);
        Event::assertDispatchedTimes(TransactionCommitted::class, 2);
    }

    public function testUpdateUsingMutationWithoutTransaction(): void
    {
        Event::fake();

        $userId = $this->generateUuid();
        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->updateUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'tester']);

        $this->assertEquals(['userId' => $userId, 'name' => 'tester'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        Event::assertDispatchedTimes(TransactionBeginning::class, 2);
        Event::assertDispatchedTimes(MutatingData::class, 2);
        Event::assertDispatchedTimes(TransactionCommitted::class, 2);
    }

    public function testDeleteUsingMutationWithTransaction(): void
    {
        Event::fake();

        $userId = $this->generateUuid();
        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->transaction(function () use ($conn, $userId) {
            $conn->deleteUsingMutation(self::TABLE_NAME_USER, $userId);
        });

        $this->assertNull($conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        Event::assertDispatchedTimes(TransactionBeginning::class, 2);
        Event::assertDispatchedTimes(MutatingData::class, 2);
        Event::assertDispatchedTimes(TransactionCommitted::class, 2);
    }

    public function testDeleteUsingMutationWithoutTransaction(): void
    {
        Event::fake();

        $userId = $this->generateUuid();
        $conn = $this->getDefaultConnection();
        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId, 'name' => 'test']);
        $conn->deleteUsingMutation(self::TABLE_NAME_USER, $userId);

        $this->assertNull($conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        Event::assertDispatchedTimes(TransactionBeginning::class, 2);
        Event::assertDispatchedTimes(MutatingData::class, 2);
        Event::assertDispatchedTimes(TransactionCommitted::class, 2);
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

    public function test_AuthCache_works(): void
    {
        $config = $this->app['config']->get('database.connections.main');

        $authCache = new ArrayAdapter();
        $conn = new Connection($config['instance'], $config['database'], '', $config, $authCache);
        $this->setUpDatabaseOnce($conn);

        $conn->selectOne('SELECT 1');

        $this->assertInstanceOf(Connection::class, $conn);
        $this->assertNotEmpty($authCache->getValues(), 'After executing some query, session cache is created.');
    }

    public function test_AuthCache_with_FileSystemAdapter(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->select('SELECT 1');
        self::assertDirectoryExists(storage_path("framework/spanner/{$conn->getName()}/auth"));
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

    public function test_session_pool_with_FileSystemAdapter(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->select('SELECT 1');
        self::assertDirectoryExists(storage_path("framework/spanner/{$conn->getName()}/session"));
    }

    public function test_clearSessionPool(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->warmupSessionPool();
        $conn->clearSessionPool();
        self::assertSame(1, $conn->warmupSessionPool());
    }

    public function test_listSessions(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->select('SELECT 1');

        $sessions = $conn->listSessions();
        $this->assertNotEmpty($sessions);
        $this->assertInstanceOf(SessionInfo::class, $sessions[0]);
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

    public function test_Connection_transaction_reset_on_exceptions(): void
    {
        $conn = $this->getDefaultConnection();

        try {
            $conn->transaction(function (Connection $conn) {
                self::assertTrue($conn->inTransaction());
                self::assertNotNull($conn->getCurrentTransaction());
                self::assertSame(1, $conn->transactionLevel());
                throw new NotFoundException('NG');
            });
        } catch(NotFoundException) {
            // do nothing.
        }

        self::assertfalse($conn->inTransaction());
        self::assertNull($conn->getCurrentTransaction());
        self::assertSame(0, $conn->transactionLevel());
    }
}
