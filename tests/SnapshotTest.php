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

use Colopl\Spanner\TimestampBound\ExactStaleness;
use Colopl\Spanner\TimestampBound\StrongRead;
use LogicException;
use RuntimeException;

class SnapshotTest extends TestCase
{
    public function test_snapshot(): void
    {
        $conn = $this->getDefaultConnection();

        $conn->transaction(function () use ($conn) {
            $this->assertFalse($conn->inSnapshot());
            $conn->table(self::TABLE_NAME_USER)->insert(['userId' => $this->generateUuid(), 'name' => 't']);
        });

        $this->assertFalse($conn->inSnapshot());
        $result = $conn->snapshot(new StrongRead(), function () use ($conn) {
            $this->assertTrue($conn->inSnapshot());
            // call it multiple times
            $this->assertSame('t', $conn->table(self::TABLE_NAME_USER)->value('name'));
            $this->assertSame('t', $conn->table(self::TABLE_NAME_USER)->value('name'));

            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function test_builder_snapshot(): void
    {
        $conn = $this->getDefaultConnection();

        $conn->transaction(function () use ($conn) {
            $this->assertFalse($conn->inSnapshot());
            $conn->table(self::TABLE_NAME_USER)->insert(['userId' => $this->generateUuid(), 'name' => 't']);
        });

        $this->assertFalse($conn->inSnapshot());
        $result = $conn->table(self::TABLE_NAME_USER)->snapshot(new StrongRead())->first();

        $this->assertFalse($conn->inSnapshot());
        $this->assertNotNull($result);
        $this->assertSame('t', $result['name']);
    }

    public function test_snapshot_with_staleness(): void
    {
        $conn = $this->getDefaultConnection();

        $conn->transaction(function () use ($conn) {
            $conn->table(self::TABLE_NAME_USER)->insert(['userId' => $this->generateUuid(), 'name' => 't']);
        });

        $conn->snapshot(new ExactStaleness(10), function () use ($conn) {
            $this->assertNull($conn->table(self::TABLE_NAME_USER)->first());
            $this->assertSame(0, $conn->table(self::TABLE_NAME_USER)->count());
        });

        $conn->snapshot(new StrongRead(), function () use ($conn) {
            $this->assertNotNull($conn->table(self::TABLE_NAME_USER)->first());
            $this->assertSame(1, $conn->table(self::TABLE_NAME_USER)->count());
        });
    }

    public function test_snapshot_can_call_after_error(): void
    {
        $conn = $this->getDefaultConnection();

        try {
            $conn->snapshot(new ExactStaleness(10), function () use ($conn) {
                $this->assertSame(0, $conn->table(self::TABLE_NAME_USER)->count());
                throw new RuntimeException('error');
            });
        } catch (RuntimeException $e) {
            // ignore
        }

        $conn->transaction(function () use ($conn) {
            $conn->table(self::TABLE_NAME_USER)->insert(['userId' => $this->generateUuid(), 'name' => 't']);
        });

        $conn->snapshot(new ExactStaleness(0), function () use ($conn) {
            $this->assertSame(1, $conn->table(self::TABLE_NAME_USER)->count());
        });
    }

    public function test_snapshot_fails_on_nested(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Nested snapshots are not supported.');

        $conn = $this->getDefaultConnection();
        $conn->snapshot(new ExactStaleness(10), function () use ($conn) {
            $conn->snapshot(new StrongRead(), function () {
            });
        });
    }

    public function test_snapshot_fails_in_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Nested transactions are not supported by this client.');

        $conn = $this->getDefaultConnection();
        $conn->transaction(function () use ($conn) {
            $conn->snapshot(new StrongRead(), function () use ($conn) {
                $conn->select('SELECT 1');
            });
        });
    }

    public function test_snapshot_fails_when_transaction_called_inside(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Calling transaction() inside a snapshot is not supported.');

        $conn = $this->getDefaultConnection();
        $conn->snapshot(new StrongRead(), function () use ($conn) {
            $conn->transaction(function () use ($conn) {
                $conn->select('SELECT 1');
            });
        });
    }
}
