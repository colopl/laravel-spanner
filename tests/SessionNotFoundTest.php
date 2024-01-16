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
        bool $useSessionPool = true,
    ): Connection
    {
        $config = $this->app['config']->get('database.connections.main');

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
        $conn = $this->getSessionNotFoundConnection();

        $conn->selectOne('SELECT 1');

        $this->deleteSession($conn);

        $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
    }

    public function test_session_not_found_without_session_pool(): void
    {
        $conn = $this->getSessionNotFoundConnection(false);

        $conn->selectOne('SELECT 1');

        $this->deleteSession($conn);

        $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
    }

    public function test_session_not_found_in_transaction(): void
    {
        $conn = $this->getSessionNotFoundConnection();

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
        $conn = $this->getSessionNotFoundConnection();

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
        $conn = $this->getSessionNotFoundConnection();

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
        $conn = $this->getSessionNotFoundConnection();

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
        $conn = $this->getSessionNotFoundConnection();

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
}
