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

use Exception;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Transaction;
use Colopl\Spanner\Connection;
use Google\Cloud\Spanner\TransactionalReadInterface;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

class TransactionTest extends TestCase
{
    public function testBegin(): void
    {
        $conn = $this->getDefaultConnection();

        $conn->beginTransaction();
        $t = $conn->getCurrentTransaction();
        $this->assertInstanceOf(Transaction::class, $t);
        $this->assertEquals(TransactionalReadInterface::STATE_ACTIVE, $t->state());

        $conn->commit();
        $this->assertEquals(TransactionalReadInterface::STATE_COMMITTED, $t->state());
    }

    public function testCallback(): void
    {
        $conn = $this->getDefaultConnection();
        $result = $conn->transaction(function (Connection $conn) {
            $this->assertEquals(TransactionalReadInterface::STATE_ACTIVE, $conn->getCurrentTransaction()?->state());
            return 1;
        });
        $this->assertSame(1, $result);
    }

    public function testCommit(): void
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];

        $conn->transaction(function (Connection $conn) use($qb, $insertRow) {
            $this->assertEquals(TransactionalReadInterface::STATE_ACTIVE, $conn->getCurrentTransaction()?->state());
            $qb->insert($insertRow);
        });

        $this->assertDatabaseHas($tableName, $insertRow);
    }

    public function testRollbackBeforeCommit(): void
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];

        try {
            $conn->transaction(function (Connection $conn) {
                $this->assertEquals(TransactionalReadInterface::STATE_ACTIVE, $conn->getCurrentTransaction()?->state());
                throw new RuntimeException('abort test');
            });
        } catch (RuntimeException $ex) {
            if (!Str::contains($ex->getMessage(), 'abort test')) {
                throw $ex;
            }
        }

        $this->assertDatabaseMissing($tableName, $insertRow);
    }

    public function testNestedTransaction(): void
    {
        Event::fake();

        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];

        $conn->transaction(function () use ($conn, $qb, $insertRow) {
            $this->assertEquals(1, $conn->transactionLevel());
            $conn->transaction(function () use ($conn, $qb, $insertRow) {
                $this->assertEquals(2, $conn->transactionLevel());
                $conn->transaction(function () use ($conn, $qb, $insertRow) {
                    $this->assertEquals(3, $conn->transactionLevel());
                    $qb->insert($insertRow);
                });
                $this->assertEquals(2, $conn->transactionLevel());
            });
            $this->assertEquals(1, $conn->transactionLevel());
        });
        $this->assertDatabaseHas($tableName, $insertRow);
        Event::assertDispatchedTimes(TransactionCommitted::class, 3);

        $cnt = 0;
        $conn->transaction(function () use ($conn, &$cnt) {
            $cnt++;
            $conn->transaction(function () use (&$cnt) {
                if ($cnt < 2) {
                    throw new AbortedException('aborted');
                }
            });
        });
        $this->assertEquals(2, $cnt);
        Event::assertDispatchedTimes(TransactionCommitted::class);
    }

    public function testReadOnTransaction(): void
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];

        $conn->transaction(function () use ($qb, $insertRow) {
            $qb->insert($insertRow);
        });

        $this->assertDatabaseHas($tableName, $insertRow);
    }

    public function test_afterCommit(): void
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $count = 0;
        $conn->transaction(function (Connection $conn) use ($qb, &$count) {
            $qb->insert(['userId' => $this->generateUuid(), 'name' => 't']);
            $conn->afterCommit(static function() use (&$count) { $count++; });
        });

        // Should not be called on second try.
        $conn->transaction(function () use ($qb) {
            $qb->insert(['userId' => $this->generateUuid(), 'name' => 't']);
        });

        $this->assertSame(1, $count);
    }

    public function test_afterCommit_not_called_on_rollback(): void
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $count = 0;
        try {
            $conn->transaction(function (Connection $conn) use ($qb, &$count) {
                $qb->insert(['userId' => $this->generateUuid(), 'name' => 't']);
                $conn->afterCommit(static function() use (&$count) {
                    $count++;
                });
                throw new RuntimeException('fail');
            });
        } catch (RuntimeException) {
            // do nothing
        }

        $this->assertSame(0, $count);
    }

    public function testRetrySuccess(): void
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];

        $maxAttempts = 5;
        $cnt = 0;
        $conn->transaction(function () use ($qb, $insertRow, &$cnt) {
            $cnt++;
            if ($cnt < 4) {
                throw new AbortedException('mock aborted exception');
            }
            // will success on the 4th try
            $qb->insert($insertRow);
        }, $maxAttempts);

        $this->assertDatabaseHas($tableName, $insertRow);
    }

    /**
     * NOTE: This test will take at least 10 seconds to complete
     */
    public function testLockTimeout(): void
    {
        $conn = $this->getDefaultConnection();
        $conn2 = $this->getAlternativeConnection();

        $tableName = self::TABLE_NAME_USER;

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];
        $qb = $conn->table($tableName);
        $qb2 = $conn2->table($tableName);

        $qb->insert($insertRow);
        $mutation = ['userId' => $insertRow['userId'], 'name' => 'updated'];

        $caughtException = null;
        try {
            $conn->transaction(function () use ($conn2, $qb, $qb2, $mutation) {
                // SELECTing within a read-write transaction causes row to acquire shared lock
                 $qb->where('userId', $mutation['userId'])->first();

                $conn2->transaction(function () use ($qb2, $mutation) {
                    // This will time out and result in AbortedException since row is locked
                    $qb2->where('userId', $mutation['userId'])->update(['name' => $mutation['name']]);
                }, 1);
            }, 1);
        } catch (Exception $ex) {
            $caughtException = $ex;
        }

        $this->assertInstanceOf(AbortedException::class, $caughtException);
        $this->assertStringContainsString('ABORTED', $caughtException->getMessage());
    }

    public function testAbortedException(): void
    {
        Event::fake();

        $retries = 3;
        $conn = $this->getDefaultConnection();
        try {
            $conn->transaction(fn() => throw new AbortedException('abort'), $retries);
        } catch (AbortedException) {

        }

        Event::assertDispatchedTimes(TransactionCommitted::class, 0);
        Event::assertDispatchedTimes(TransactionRolledBack::class, $retries);
    }
}
