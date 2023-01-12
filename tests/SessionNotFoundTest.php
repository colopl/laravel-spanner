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
use Colopl\Spanner\Session\CacheSessionPool;
use Google\Cloud\Core\Exception\NotFoundException;
use Illuminate\Database\QueryException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class SessionNotFoundTest extends TestCase
{
    private function deleteSession(Connection $connection): void
    {
        // delete session on spanner side
        $connection->getSpannerDatabase()->__debugInfo()['session']->delete();
    }

    private function getSessionNotFoundConnection(string $sessionNotFoundErrorMode): Connection
    {
        $config = $this->app['config']->get('database.connections.main');

        // old behavior, just raise QueryException
        $config['sessionNotFoundErrorMode'] = $sessionNotFoundErrorMode;

        $cacheItemPool = new ArrayAdapter();
        $cacheSessionPool = new CacheSessionPool($cacheItemPool);
        $conn = new Connection($config['instance'], $config['database'], '', $config, null, $cacheSessionPool);

        $this->setUpDatabaseOnce($conn);
        return $conn;
    }

    public function testSessionNotFoundHandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $conn->selectOne('SELECT 1');

        $this->deleteSession($conn);

        $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
    }

    public function testInTransactionSessionNotFoundHandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {

            if ($passes === 0) {
                $this->deleteSession($conn);
                $passes++;
            }

            $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));

            $passes++;
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testInTransactionCommitSessionNotFoundHandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {

            $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));

            if ($passes === 0) {
                $this->deleteSession($conn);
            }
            $passes++;
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testInTransactionRollbackSessionNotFoundHandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {

            $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));

            if ($passes === 0) {
                $this->deleteSession($conn);
            }
            $passes++;
            // explicit rollback should force rerunning of code
            $conn->rollback();
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testNestedTransactionsSessionNotFoundHandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {
            $conn->transaction(function () use ($conn, &$passes) {
                $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));

                if ($passes === 0) {
                    $this->deleteSession($conn);
                }
                $passes++;
            });
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testCursorSessionNotFoundHandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {
            $cursor = $conn->cursor('SELECT 12345');

            if ($passes === 0) {
                $this->deleteSession($conn);
                $passes++;
            }

            $this->assertEquals([12345], $cursor->current());

            $passes++;
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testSessionNotFoundUnhandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::THROW_EXCEPTION);

        $conn->selectOne('SELECT 1');

        // deliberately delete session on spanner side
        $this->deleteSession($conn);

        $this->expectException(QueryException::class);

        // the string is used in sessionNotFoundWrapper() to catch 'session not found' error,
        // if google changes it then string should be changed in Connection::SESSION_NOT_FOUND_CONDITION
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        $conn->selectOne('SELECT 1');
    }

    public function testCursorSessionNotFoundUnhandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::THROW_EXCEPTION);

        $cursor = $conn->cursor('SELECT 1');

        // deliberately delete session on spanner side
        $this->deleteSession($conn);

        $this->expectException(NotFoundException::class);

        // the string is used in sessionNotFoundWrapper() to catch 'session not found' error,
        // if google changes it then string should be changed in Connection::SESSION_NOT_FOUND_CONDITION
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        iterator_to_array($cursor);
    }

    public function testInTransactionSessionNotFoundUnhandledError(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::THROW_EXCEPTION);

        $this->expectException(NotFoundException::class);

        // the string is used in sessionNotFoundWrapper() to catch 'session not found' error,
        // if google changes it then string should be changed in Connection::SESSION_NOT_FOUND_CONDITION
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {

            if ($passes === 0) {
                $this->deleteSession($conn);
                $passes++;
            }

            $conn->selectOne('SELECT 12345');
        });
    }
}
