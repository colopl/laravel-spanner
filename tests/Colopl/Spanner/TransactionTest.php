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

use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Spanner\Transaction;
use Colopl\Spanner\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

class TransactionTest extends TestCase
{
    protected $preparingTestDatabaseRequired = true;

    public function testBegin()
    {
        $conn = $this->getDefaultConnection();

        $conn->beginTransaction();
        $t = $conn->getCurrentTransaction();
        $this->assertInstanceOf(Transaction::class, $t);
        $this->assertEquals(Transaction::STATE_ACTIVE, $t->state());

        $conn->commit();
        $this->assertEquals(Transaction::STATE_COMMITTED, $t->state());
    }

    public function testCallback()
    {
        $conn = $this->getDefaultConnection();
        $result = $conn->transaction(function (Connection $conn) {
            $this->assertEquals(Transaction::STATE_ACTIVE, $conn->getCurrentTransaction()->state());
            return 1;
        });
        $this->assertSame(1, $result);
    }

    public function testCommit()
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];

        $conn->transaction(function (Connection $conn) use($qb, $insertRow) {
            $this->assertEquals(Transaction::STATE_ACTIVE, $conn->getCurrentTransaction()->state());
            $qb->insert($insertRow);
        });

        $this->assertDatabaseHas($tableName, $insertRow);
    }

    public function testRollbackBeforeCommit()
    {
        $conn = $this->getDefaultConnection();

        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];

        try {
            $conn->transaction(function (Connection $conn) use ($qb, $insertRow) {
                $this->assertEquals(Transaction::STATE_ACTIVE, $conn->getCurrentTransaction()->state());
                throw new \RuntimeException('abort test');
            });
        } catch (\RuntimeException $ex) {
            if (!str_contains($ex->getMessage(), 'abort test')) {
                throw $ex;
            }
        }

        $this->assertDatabaseMissing($tableName, $insertRow);
    }

    public function testNestedTransaction()
    {
        $conn = $this->getDefaultConnection();
        $catchedException = null;
        try {
            $conn->transaction(function () use ($conn) {
                $this->assertEquals(1, $conn->transactionLevel());
                $conn->transaction(function () {});
            });
        } catch (\Exception $ex) {
            $catchedException = $ex;
        }
        $this->assertContains('Nested transactions are not supported', $catchedException->getMessage());
        $this->assertInstanceOf(\BadMethodCallException::class, $catchedException);
    }

    public function testReadOnTransaction()
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

    public function testRetrySuccess()
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
                throw new \Google\Cloud\Core\Exception\AbortedException('mock aborted exception');
            } else {
                // will success on the 4th try
                $qb->insert($insertRow);
            }
        }, $maxAttempts);

        $this->assertDatabaseHas($tableName, $insertRow);
    }

    /**
     * NOTE: This test will take at least 10 seconds to complete
     */
    public function testLockTimeout()
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

        $catchedException = null;
        try {
            $conn->transaction(function () use ($conn2, $qb, $qb2, $mutation) {
                // SELECTing within a read-write transaction causes row to aquire shared lock
                $findRow = $qb->where('userId', $mutation['userId'])->first();

                $conn2->transaction(function () use ($qb2, $mutation) {
                    // will timeout and result in AbortedException since row is locked
                    $qb2->where('userId', $mutation['userId'])->update(['name' => $mutation['name']]);
                }, 1);
            }, 1);
        } catch (\Exception $ex) {
            $catchedException = $ex;
        }
        $this->assertInstanceOf(AbortedException::class, $catchedException);
        $this->assertContains('aborted.', $catchedException->getMessage());
    }

    /**
     * @throws \Throwable
     */
    public function testAbortedException()
    {
        $committedCount = 0;
        $this->app['events']->listen(TransactionCommitted::class, function ($ev) use(&$committedCount) {
            $committedCount++;
        });

        $rolledBackCount = 0;
        $this->app['events']->listen(TransactionRolledBack::class, function ($ev) use (&$rolledBackCount) {
            $rolledBackCount++;
        });

        $conn = $this->getDefaultConnection();
        try {
            $conn->transaction(function () use ($conn) {
                throw new AbortedException('abort');
            });
        } catch (AbortedException $ex) {}

        $this->assertEquals(0, $committedCount);
        $this->assertEquals(10, $rolledBackCount);
    }
}
