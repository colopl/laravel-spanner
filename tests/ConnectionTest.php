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
use Generator;
use Google\Auth\FetchAuthTokenInterface;
use Google\Cloud\Spanner\Duration;
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
use LogicException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use function dirname;
use function fileperms;
use function sprintf;
use function substr;

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
        $this->assertSame([12345], $conn->selectOne('SELECT 12345'));
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

    public function test_select(): void
    {
        $conn = $this->getDefaultConnection();
        $values = $conn->select('SELECT 12345');
        $this->assertCount(1, $values);
        $this->assertSame(12345, $values[0][0]);
    }

    public function test_selectWithOptions(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->table(self::TABLE_NAME_USER)->insert(['userId' => $this->generateUuid(), 'name' => __FUNCTION__]);
        $values = $conn->selectWithOptions('SELECT * FROM ' . self::TABLE_NAME_USER, [], ['exactStaleness' => new Duration(10)]);
        $this->assertEmpty($values);
    }

    public function test_cursorWithOptions(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->table(self::TABLE_NAME_USER)->insert(['userId' => $this->generateUuid(), 'name' => __FUNCTION__]);
        $cursor = $conn->cursorWithOptions('SELECT * FROM ' . self::TABLE_NAME_USER, [], ['exactStaleness' => new Duration(10)]);
        $this->assertInstanceOf(Generator::class, $cursor);
        $this->assertNull($cursor->current());
    }

    public function test_statement_with_select(): void
    {
        $executedCount = 0;
        $this->app['events']->listen(QueryExecuted::class, function () use (&$executedCount) { $executedCount++; });

        $conn = $this->getDefaultConnection();
        $res = $conn->statement('SELECT ?', ['12345']);

        $this->assertTrue($res);
        $this->assertSame(1, $executedCount);
    }

    public function test_statement_with_dml(): void
    {
        $conn = $this->getDefaultConnection();
        $userId = $this->generateUuid();
        $executedCount = 0;
        $this->app['events']->listen(QueryExecuted::class, function () use (&$executedCount) { $executedCount++; });

        $res[] = $conn->statement('INSERT '.self::TABLE_NAME_USER.' (`userId`, `name`) VALUES (?,?)', [$userId, __FUNCTION__]);
        $res[] = $conn->statement('UPDATE '.self::TABLE_NAME_USER.' SET `name`=? WHERE `userId`=?', [__FUNCTION__.'2', $userId]);
        $res[] = $conn->statement('DELETE '.self::TABLE_NAME_USER.' WHERE `userId`=?', [$this->generateUuid()]);

        $this->assertTrue($res[0]);
        $this->assertTrue($res[1]);
        $this->assertTrue($res[2]);
        $this->assertSame(3, $executedCount);
    }

    public function test_unprepared_with_select(): void
    {
        $executedCount = 0;
        $this->app['events']->listen(QueryExecuted::class, function () use (&$executedCount) { $executedCount++; });

        $conn = $this->getDefaultConnection();
        $res = $conn->unprepared('SELECT 12345');

        $this->assertTrue($res);
        $this->assertSame(1, $executedCount);
    }

    public function test_unprepared_with_dml(): void
    {
        $conn = $this->getDefaultConnection();
        $userId = $this->generateUuid();
        $executedCount = 0;
        $this->app['events']->listen(QueryExecuted::class, function () use (&$executedCount) { $executedCount++; });

        $res[] = $conn->unprepared('INSERT '.self::TABLE_NAME_USER.' (`userId`, `name`) VALUES (\''.$userId.'\',\''.__FUNCTION__.'\')');
        $res[] = $conn->unprepared('UPDATE '.self::TABLE_NAME_USER.' SET `name`=\''.__FUNCTION__.'2'.'\' WHERE `userId`=\''.$userId.'\'');
        $res[] = $conn->unprepared('DELETE '.self::TABLE_NAME_USER.' WHERE `userId`=\''.$userId.'\'');

        $this->assertTrue($res[0]);
        $this->assertTrue($res[1]);
        $this->assertTrue($res[2]);
        $this->assertSame(3, $executedCount);
    }

    public function test_pretend(): void
    {
        $conn = $this->getDefaultConnection();

        $executedCount = 0;
        $this->app['events']->listen(QueryExecuted::class, function ($e) use (&$executedCount) { $executedCount++; });

        $uuid = $this->generateUuid();
        $conn->pretend(function(Connection $conn) use ($uuid) {
            $resSelect = $conn->select('SELECT 12345');
            $this->assertSame([], $resSelect);

            $resInsert = $conn->table(self::TABLE_NAME_USER)->insert(['userId' => $uuid, 'name' => __FUNCTION__]);
            $this->assertTrue($resInsert);
        });
        $this->assertSame(2, $executedCount);
        $this->assertFalse($conn->table(self::TABLE_NAME_USER)->where('userId', $uuid)->exists());
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

        $this->assertSame(['userId' => $userId, 'name' => 'tester'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
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

        $this->assertSame(['userId' => $userId, 'name' => 'tester'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId)->first());
        Event::assertDispatchedTimes(TransactionBeginning::class, 2);
        Event::assertDispatchedTimes(MutatingData::class, 2);
        Event::assertDispatchedTimes(TransactionCommitted::class, 2);
    }

    public function testInsertOrUpdateUsingMutationWithTransaction(): void
    {
        Event::fake();

        $userId1 = $this->generateUuid();
        $userId2 = $this->generateUuid();
        $conn = $this->getDefaultConnection();

        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId1, 'name' => 'test1']);

        $conn->transaction(function () use ($conn, $userId1, $userId2) {
            $conn->insertOrUpdateUsingMutation(self::TABLE_NAME_USER, [
                ['userId' => $userId1, 'name' => 'tester1'],
                ['userId' => $userId2, 'name' => 'tester2'],
            ]);
        });

        $this->assertSame(['userId' => $userId1, 'name' => 'tester1'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId1)->first());
        $this->assertSame(['userId' => $userId2, 'name' => 'tester2'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId2)->first());
        Event::assertDispatchedTimes(TransactionBeginning::class, 2);
        Event::assertDispatchedTimes(MutatingData::class, 2);
        Event::assertDispatchedTimes(TransactionCommitted::class, 2);
    }

    public function testInsertOrUpdateUsingMutationWithoutTransaction(): void
    {
        Event::fake();

        $userId1 = $this->generateUuid();
        $userId2 = $this->generateUuid();
        $conn = $this->getDefaultConnection();

        $conn->insertUsingMutation(self::TABLE_NAME_USER, ['userId' => $userId1, 'name' => 'test1']);
        $conn->insertOrUpdateUsingMutation(self::TABLE_NAME_USER, [
            ['userId' => $userId1, 'name' => 'tester1'],
            ['userId' => $userId2, 'name' => 'tester2'],
        ]);

        $this->assertSame(['userId' => $userId1, 'name' => 'tester1'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId1)->first());
        $this->assertSame(['userId' => $userId2, 'name' => 'tester2'], $conn->table(self::TABLE_NAME_USER)->where('userId', $userId2)->first());
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

        $this->assertSame(5, $executedCount);
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
        $sessionPool = new CacheSessionPool(new ArrayAdapter());
        $conn = new Connection($config['instance'], $config['database'], '', $config, $authCache, $sessionPool);
        $this->setUpDatabaseOnce($conn);

        $conn->selectOne('SELECT 1');

        $this->assertInstanceOf(Connection::class, $conn);
        $this->assertNotEmpty($authCache->getValues(), 'After executing some query, session cache is created.');
    }

    public function test_AuthCache_with_FileSystemAdapter(): void
    {
        $this->app->useStoragePath('/tmp/laravel-spanner');

        $conn = $this->getDefaultConnection();
        $conn->select('SELECT 1');

        $outputPath = $this->app->storagePath("framework/spanner");
        $this->assertFileExists($outputPath);
        $this->assertSame('0755', substr(sprintf('%o', fileperms(dirname($outputPath))), -4));
        $this->assertSame('0755', substr(sprintf('%o', fileperms($outputPath)), -4));
    }

    public function test_session_pool(): void
    {
        $config = $this->app['config']->get('database.connections.main');

        $cacheItemPool = new ArrayAdapter();
        $cacheSessionPool = new CacheSessionPool($cacheItemPool);
        $conn = new Connection($config['instance'], $config['database'], '', $config, null, $cacheSessionPool);
        $this->setUpDatabaseOnce($conn);
        $this->assertInstanceOf(Connection::class, $conn);

        $conn->selectOne('SELECT 1');
        $this->assertNotEmpty($cacheItemPool->getValues(), 'After executing some query, cache is created.');

        $conn->clearSessionPool();
        $this->assertEmpty($cacheItemPool->getValues(), 'After clearing the session pool, cache is removed.');
    }

    public function test_session_pool_with_FileSystemAdapter(): void
    {
        $this->app->useStoragePath('/tmp/laravel-spanner');

        $conn = $this->getDefaultConnection();
        $conn->select('SELECT 1');

        $outputPath = $this->app->storagePath("framework/spanner");
        $this->assertFileExists($outputPath);
        $this->assertSame('0755', substr(sprintf('%o', fileperms(dirname($outputPath))), -4));
        $this->assertSame('0755', substr(sprintf('%o', fileperms($outputPath)), -4));
    }

    public function test_clearSessionPool(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->warmupSessionPool();
        $conn->clearSessionPool();
        $this->assertSame(1, $conn->warmupSessionPool());
    }

    public function test_listSessions(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->select('SELECT 1');

        $sessions = $conn->listSessions();
        $this->assertNotEmpty($sessions);
        $this->assertInstanceOf(SessionInfo::class, $sessions[0]);
    }

    public function test_stale_reads(): void
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
        $db->close();
        $this->assertNotEmpty($timestamp);

        $timestampBound = new StrongRead();
        $rows = $conn->selectWithOptions("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound->transactionOptions());
        $this->assertCount(1, $rows);
        $this->assertSame($uuid, $rows[0]['userId']);
        $this->assertSame('first', $rows[0]['name']);

        $oldDatetime = Carbon::instance($timestamp->get())->subSecond();

        $timestampBound = new ReadTimestamp($oldDatetime);
        $rows = $conn->selectWithOptions("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound->transactionOptions());
        $this->assertEmpty($rows);

        $timestampBound = new ExactStaleness(10);
        $rows = $conn->selectWithOptions("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound->transactionOptions());
        $this->assertEmpty($rows);

        $timestampBound = new MaxStaleness(10);
        $rows = $conn->selectWithOptions("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound->transactionOptions());
        $this->assertCount(1, $rows);
        $this->assertSame($uuid, $rows[0]['userId']);
        $this->assertSame('first', $rows[0]['name']);

        $timestampBound = new MinReadTimestamp($oldDatetime);
        $rows = $conn->selectWithOptions("SELECT * FROM ${tableName} WHERE userID = ?", [$uuid], $timestampBound->transactionOptions());
        $this->assertCount(1, $rows);
        $this->assertSame($uuid, $rows[0]['userId']);
        $this->assertSame('first', $rows[0]['name']);
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
        $this->assertSame(TransactionBeginning::class, $receivedEventClasses[0]);
        $this->assertSame(QueryExecuted::class, $receivedEventClasses[1]);
        $this->assertSame(TransactionCommitted::class, $receivedEventClasses[2]);
    }

    public function test_escape(): void
    {
        $conn = $this->getDefaultConnection();

        $this->assertSame('true', $conn->escape(true));
        $this->assertSame('false', $conn->escape(false));
        $this->assertSame('1', $conn->escape(1));
        $this->assertSame('0', $conn->escape(0));
        $this->assertSame('-1', $conn->escape(-1));
        $this->assertSame('1.1', $conn->escape(1.1));
        $this->assertSame('"a"', $conn->escape('a'));
        $this->assertSame('"\"a\\\\\\""', $conn->escape('"a\\"'));
        $this->assertSame('r"""' . "\n" . '"""', $conn->escape("\n"));
        $this->assertSame('[]', $conn->escape([]));
        $this->assertSame('["a"]', $conn->escape(['a']));
        $this->assertSame('[false]', $conn->escape([false]));
        $this->assertSame('[1]', $conn->escape([1]));
        $this->assertSame('[1.1]', $conn->escape([1.1]));
    }

    public function test_escape_nested_array(): void
    {
        $this->expectExceptionMessage('Nested arrays are not supported by Cloud Spanner');
        $this->expectException(LogicException::class);

        $conn = $this->getDefaultConnection();
        $this->assertSame('[]', $conn->escape([[]]));
    }

    public function test_getTablePrefix(): void
    {
        config()->set('database.connections.main.prefix', 'test_');
        $tablePrefix = $this->getConnection('main')->getTablePrefix();
        $this->assertSame('test_', $tablePrefix);
    }

    public function test_getQueryGrammar(): void
    {
        config()->set('database.connections.main.prefix', 'test_');
        $conn = $this->getConnection('main');
        $this->assertSame('test_', $conn->getQueryGrammar()->getTablePrefix());
    }

    public function test_getSchemaGrammar(): void
    {
        config()->set('database.connections.main.prefix', 'test_');
        $conn = $this->getConnection('main');
        $conn->useDefaultSchemaGrammar();
        $this->assertSame('test_', $conn->getSchemaGrammar()->getTablePrefix());
    }
}
