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
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ConnectionTest extends TestCase
{
    protected const TEST_DB_REQUIRED = true;

    public function testConnect()
    {
        $conn = $this->getDefaultConnection();
        $this->assertInstanceOf(Connection::class, $conn);
        $this->assertNotEmpty($conn->getName());
        $conn->disconnect();
    }

    public function testReconnect()
    {
        $conn = $this->getDefaultConnection();
        $this->assertInstanceOf(Connection::class, $conn);
        $conn->reconnect();
        $this->assertEquals(12345, $conn->selectOne('SELECT 12345')[0]);
    }

    public function testQueryLog()
    {
        $conn = $this->getDefaultConnection();
        $conn->enableQueryLog();

        $conn->select('SELECT 1');
        $this->assertCount(1, $conn->getQueryLog());

        $conn->select('SELECT 2');
        $this->assertCount(2, $conn->getQueryLog());
    }

    public function testInsertUsingMutationWithTransaction()
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

    public function testInsertUsingMutationWithoutTransaction()
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

    public function testUpdateUsingMutationWithTransaction()
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

    public function testUpdateUsingMutationWithoutTransaction()
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

    public function testDeleteUsingMutationWithTransaction()
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

    public function testDeleteUsingMutationWithoutTransaction()
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

    public function testDeleteUsingMutationWithDifferentArgs()
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

    public function testQueryExecutedEvent()
    {
        $conn = $this->getDefaultConnection();

        $executedCount = 0;
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $ev) use (&$executedCount) {
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

    public function testSession()
    {
        $conn = $this->getDefaultConnection();
        $conn->disconnect();

        $this->assertNull($conn->__debugInfo()['session'], 'At the time of creating the connection, the session has not been created yet.');

        $conn->selectOne('SELECT 1');

        $this->assertNotEmpty($conn->__debugInfo()['session'], 'After executing some query, session is created.');
    }

    public function testCredentialFetcher()
    {
        $conn = $this->getDefaultConnection();
        /** @var \Google\Auth\FetchAuthTokenInterface|null $credentialFetcher */
        $credentialFetcher = $conn->__debugInfo()['credentialFetcher'];

        $this->assertInstanceOf(\Google\Auth\FetchAuthTokenInterface::class, $credentialFetcher);
        $this->assertNotEmpty($credentialFetcher->getCacheKey());
    }

    public function testAuthCache()
    {
        $config = $this->app['config']->get('database.connections.main');

        $authCache = new ArrayAdapter();
        $conn = new Connection($config['instance'], $config['database'], '', $config, $authCache);
        $this->assertInstanceOf(Connection::class, $conn);

        $conn->selectOne('SELECT 1');
        $this->assertNotEmpty($authCache->getValues(), 'After executing some query, session cache is created.');
    }

    public function testSessionPool()
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

    public function testListSessions()
    {
        $conn = $this->getDefaultConnection();

        $sessions = $conn->listSessions();
        $this->assertNotEmpty($sessions);
        $this->assertInstanceOf(Session::class, $sessions[0]);
    }
}
