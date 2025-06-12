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

namespace Colopl\Spanner\Tests\Console;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Session\SessionInfo;
use Google\Cloud\Spanner\Session\Session;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use RuntimeException;

class SessionsCommandTest extends TestCase
{
    /**
     * @param Connection $connection
     * @param int $amount
     * @return list<Session>
     */
    protected function createSessions(Connection $connection, int $amount): array
    {
        $pool = $connection->getSpannerDatabase()->sessionPool() ?? throw new RuntimeException('unreachable');
        $sessions = [];
        for ($i = 0; $i < $amount; $i++) {
            $sessions[] = $pool->acquire(SessionPoolInterface::CONTEXT_READ);
        }
        return $sessions;
    }

    public function test_no_args(): void
    {
        foreach (['main', 'alternative'] as $name) {
            $conn = $this->getConnection($name);
            assert($conn instanceof Connection);
            $this->setUpDatabaseOnce($conn);
        }

        $this->artisan('spanner:warmup')
            ->assertSuccessful()
            ->run();

        $this->artisan('spanner:sessions')
            ->expectsOutputToContain('main contains 1 session(s).')
            ->expectsOutputToContain('alternative contains 1 session(s).')
            ->assertSuccessful()
            ->run();
    }

    public function test_with_args(): void
    {
        foreach (['main', 'alternative'] as $name) {
            $conn = $this->getConnection($name);
            assert($conn instanceof Connection);
            $this->setUpDatabaseOnce($conn);
        }

        $this->artisan('spanner:warmup', ['connections' => 'main'])
            ->assertSuccessful()
            ->run();

        $this->artisan('spanner:sessions', ['connections' => 'main'])
            ->expectsOutputToContain('main contains 1 session(s).')
            ->expectsOutputToContain('Name')
            ->doesntExpectOutputToContain('alternative')
            ->assertSuccessful()
            ->run();
    }

    public function test_no_sessions_shows_no_table(): void
    {
        $conn = $this->getDefaultConnection();

        $this->artisan('spanner:sessions', ['connections' => $conn->getName()])
            ->expectsOutputToContain('main contains 0 session(s).')
            ->doesntExpectOutputToContain('Name')
            ->assertSuccessful()
            ->run();
    }

    public function test_sort(): void
    {
        $conn = $this->getDefaultConnection();
        $this->createSessions($conn, 2);

        $list = $conn->listSessions()
            ->sortByDesc(fn(SessionInfo $s) => $s->getName())
            ->map(static fn(SessionInfo $s) => [
                $s->getName(),
                $s->getCreatedAt()?->format('Y-m-d H:i:s'),
                $s->getLastUsedAt()?->format('Y-m-d H:i:s'),
            ]);

        $this->artisan('spanner:sessions', ['connections' => 'main', '--sort' => 'name'])
            ->expectsOutput('main contains 2 session(s).')
            ->expectsTable(['Name', 'Created', 'LastUsed', 'Labels'], $list)
            ->assertSuccessful()
            ->run();
    }

    public function test_sort_order(): void
    {
        $conn = $this->getDefaultConnection();
        $this->createSessions($conn, 2);

        $list = $conn->listSessions()
            ->sortBy(fn(SessionInfo $s) => $s->getName())
            ->map(static fn(SessionInfo $s) => [
                $s->getName(),
                $s->getCreatedAt()?->format('Y-m-d H:i:s'),
                $s->getLastUsedAt()?->format('Y-m-d H:i:s'),
            ]);

        $this->artisan('spanner:sessions', ['connections' => 'main', '--sort' => 'name', '--order' => 'asc'])
            ->expectsOutput('main contains 2 session(s).')
            ->expectsTable(['Name', 'Created', 'LastUsed', 'Labels'], $list)
            ->assertSuccessful()
            ->run();
    }

    public function test_sort_patterns(): void
    {
        $conn = $this->getDefaultConnection();
        $this->createSessions($conn, 1);

        foreach (['Name', 'Created', 'LastUsed', 'Labels'] as $column) {
            foreach (['desc', 'asc'] as $order) {
                $this->artisan('spanner:sessions', ['connections' => 'main', '--sort' => $column, '--order' => $order])
                    ->assertSuccessful()
                    ->run();
            }
        }
    }

    public function test_filter_label(): void
    {
        config()->set('database.connections.main.session_pool.labels', ['pod' => 'test']);

        $conn = $this->getDefaultConnection();
        $this->setUpDatabaseOnce($conn);
        $this->createSessions($conn, 1);

        $this->artisan('spanner:sessions', ['connections' => 'main', '--label' => 'pod=test'])
            ->expectsOutput('main contains 1 session(s). (filtered by Label: pod=test)')
            ->assertSuccessful()
            ->run();
    }
}
