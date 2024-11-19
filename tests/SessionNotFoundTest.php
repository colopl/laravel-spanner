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
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Illuminate\Database\QueryException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class SessionNotFoundTest extends TestCase
{
    private function deleteSession(Connection $connection): void
    {
        // delete session on spanner side
        $connection->getSpannerDatabase()->__debugInfo()['session']->delete();
    }

    private function getSessionNotFoundConnection(
        string $sessionNotFoundErrorMode,
        bool $useSessionPool = true,
    ): Connection
    {
        $config = $this->app['config']->get('database.connections.main');

        // old behavior, just raise QueryException
        $config['sessionNotFoundErrorMode'] = $sessionNotFoundErrorMode;

        $sessionPool = $useSessionPool
            ? new CacheSessionPool(new ArrayAdapter())
            : null;

        $conn = new Connection(
            $config['instance'],
            $config['database'],
            '',
            $config,
            null,
            $sessionPool,
        );

        $this->setUpDatabaseOnce($conn);
        return $conn;
    }

    public function test_session_not_found_handling(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $conn->selectOne('SELECT 1');

        $this->deleteSession($conn);

        $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
    }

    public function test_session_not_found_without_session_pool(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL, false);

        $conn->selectOne('SELECT 1');

        $this->deleteSession($conn);

        $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
    }

    public function test_session_not_found_in_transaction(): void
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

    public function test_session_not_found_when_committing(): void
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

    public function test_session_not_found_when_rolling_back(): void
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

    public function test_session_not_found_on_nested_transaction(): void
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

    public function test_session_not_found_on_cursor(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::CLEAR_SESSION_POOL);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {
            if ($passes === 0) {
                $this->deleteSession($conn);
                $passes++;
            }

            $cursor = $conn->cursor('SELECT 12345');

            $this->assertEquals([12345], $cursor->current());

            $passes++;
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function test_session_not_found_throw_exception_on_query(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::THROW_EXCEPTION);

        $conn->selectOne('SELECT 1');

        // deliberately delete session on spanner side
        $this->deleteSession($conn);

        $this->expectException(QueryException::class);

        // the string is used in sessionNotFoundWrapper() to catch 'session not found' error,
        // if google changes it then string should be changed in Connection::SESSION_NOT_FOUND_CONDITION
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        try {
            $conn->selectOne('SELECT 1');
        } catch (QueryException $e) {
            $conn->disconnect();
            $conn->clearSessionPool();
            throw $e;
        }
    }

    public function test_session_not_found_throw_exception_on_cursor(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::THROW_EXCEPTION);

        $cursor = $conn->cursor('SELECT 1');

        // deliberately delete session on spanner side
        $this->deleteSession($conn);

        $this->expectException(NotFoundException::class);

        // the string is used in sessionNotFoundWrapper() to catch 'session not found' error,
        // if google changes it then string should be changed in Connection::SESSION_NOT_FOUND_CONDITION
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        try {
            iterator_to_array($cursor);
        } catch (NotFoundException $e) {
            $conn->disconnect();
            $conn->clearSessionPool();
            throw $e;
        }
    }

    public function test_session_not_found_throw_exception_in_transaction(): void
    {
        $conn = $this->getSessionNotFoundConnection(Connection::THROW_EXCEPTION);

        $this->expectException(NotFoundException::class);

        // the string is used in sessionNotFoundWrapper() to catch 'session not found' error,
        // if google changes it then string should be changed in Connection::SESSION_NOT_FOUND_CONDITION
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        $passes = 0;

        try {
            $conn->transaction(function () use ($conn, &$passes) {
                if ($passes === 0) {
                    $this->deleteSession($conn);
                    $passes++;
                }

                $conn->selectOne('SELECT 12345');
            });
        } catch (NotFoundException $e) {
            $conn->disconnect();
            $conn->clearSessionPool();
            throw $e;
        }
    }
}
