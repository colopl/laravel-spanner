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
    private function deleteSession(Connection $connection)
    {
        // delete session on spanner side
        $connection->getSpannerDatabase()->__debugInfo()['session']->delete();
    }

    public function testSessionNotFoundHandledError()
    {
        $conn = $this->getDefaultConnection();

        $conn->selectOne('SELECT 1');

        $this->deleteSession($conn);

        $this->assertEquals(12345, $conn->selectOne('SELECT 12345')[0]);
    }

    public function testInTransactionSessionNotFoundHandledError()
    {
        $conn = $this->getDefaultConnection();

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {

            if ($passes == 0) {
                $this->deleteSession($conn);
                $passes++;
            }

            $this->assertEquals(12345, $conn->selectOne('SELECT 12345')[0]);

            $passes++;
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testInTransactionCommitSessionNotFoundHandledError()
    {
        $conn = $this->getDefaultConnection();

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {

            $this->assertEquals(12345, $conn->selectOne('SELECT 12345')[0]);

            if ($passes == 0) {
                $this->deleteSession($conn);
            }
            $passes++;
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testNestedTransactionsSessionNotFoundHandledError()
    {
        $conn = $this->getDefaultConnection();

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {
            $conn->transaction(function () use ($conn, &$passes) {
                $this->assertEquals(12345, $conn->selectOne('SELECT 12345')[0]);

                if ($passes == 0) {
                    $this->deleteSession($conn);
                }
                $passes++;
            });
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testCusrorSessionNotFoundHandledError()
    {
        $conn = $this->getDefaultConnection();

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {
            $cursor = $conn->cursor('SELECT 12345');

            if ($passes == 0) {
                $this->deleteSession($conn);
                $passes++;
            }

            $this->assertEquals(12345, $cursor->current()[0]);

            $passes++;
        });
        $this->assertEquals(2, $passes, 'Transaction should be called twice');
    }

    public function testSessionNotFoundUnhandledError()
    {
        $config = $this->app['config']->get('database.connections.main');

        // old behavior, just raise QueryException
        $config['sessionNotFoundErrorMode'] = false;

        $cacheItemPool = new ArrayAdapter();
        $cacheSessionPool = new CacheSessionPool($cacheItemPool);
        $conn = new Connection($config['instance'], $config['database'], '', $config, null, $cacheSessionPool);
        $this->assertInstanceOf(Connection::class, $conn);

        $conn->selectOne('SELECT 1');
        $this->assertNotEmpty($cacheItemPool->getValues(), 'After executing some query, cache is created.');

        // deliberately delete session on spanner side
        $this->deleteSession($conn);

        $this->expectException(QueryException::class);

        // that string is used in sessionNotFoundWrapper() to catch session not found error,
        // if google changes it then string should be changed in sessionNotFoundWrapper() as well
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        $conn->selectOne('SELECT 1');
    }

    public function testCursorSessionNotFoundUnhandledError()
    {
        $config = $this->app['config']->get('database.connections.main');

        // old behavior, just raise QueryException
        $config['sessionNotFoundErrorMode'] = false;

        $cacheItemPool = new ArrayAdapter();
        $cacheSessionPool = new CacheSessionPool($cacheItemPool);
        $conn = new Connection($config['instance'], $config['database'], '', $config, null, $cacheSessionPool);
        $this->assertInstanceOf(Connection::class, $conn);

        $cursor = $conn->cursor('SELECT 1');
        $this->assertNotEmpty($cacheItemPool->getValues(), 'After executing some query, cache is created.');

        // deliberately delete session on spanner side
        $this->deleteSession($conn);

        $this->expectException(NotFoundException::class);

        // that string is used in sessionNotFoundWrapper() to catch session not found error,
        // if google changes it then string should be changed in sessionNotFoundWrapper() as well
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        iterator_to_array($cursor);
    }

    public function testInTransactionSessionNotFoundUnhandledError()
    {
        $config = $this->app['config']->get('database.connections.main');

        // old behavior, just raise QueryException
        $config['sessionNotFoundErrorMode'] = false;

        $cacheItemPool = new ArrayAdapter();
        $cacheSessionPool = new CacheSessionPool($cacheItemPool);
        $conn = new Connection($config['instance'], $config['database'], '', $config, null, $cacheSessionPool);
        $this->assertInstanceOf(Connection::class, $conn);

        $this->expectException(NotFoundException::class);

        // that string is used in sessionNotFoundWrapper() to catch session not found error,
        // if google changes it then string should be changed in sessionNotFoundWrapper() as well
        $this->expectExceptionMessage($conn::SESSION_NOT_FOUND_CONDITION);

        $passes = 0;

        $conn->transaction(function () use ($conn, &$passes) {

            if ($passes == 0) {
                $this->deleteSession($conn);
                $passes++;
            }

            $conn->selectOne('SELECT 12345');
        });
    }
}
