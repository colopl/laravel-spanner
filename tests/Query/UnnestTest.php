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

namespace Colopl\Spanner\Tests\Query;

use Colopl\Spanner\Tests\Eloquent\User;
use Colopl\Spanner\Tests\TestCase;

class UnnestTest extends TestCase
{
    protected function createTestUser(): User
    {
        $user = new User();
        $user->userId = $this->generateUuid();
        $user->name = 'test user on EloquentTest';
        return $user;
    }

    public function testUnnesting(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;

        $testDataCount = 3;
        $insertValues = [];
        for ($i = 0; $i < $testDataCount; $i++) {
            $insertValues[] = $this->generateTestRow();
        }
        $qb = $conn->table($tableName);
        $qb->insert($insertValues);

        $ids = $qb->pluck('testId')->sort()->values();

        $qb = $qb->whereInUnnest('testId', $ids);
        $sql = $qb->toSql();
        $results = $qb->get('testId')->pluck('testId')->sort()->values();

        $this->assertSame('select * from `Test` where `testId` in unnest(?)', $sql);
        $this->assertCount(3, $results);
        $this->assertSame($ids->all(), $results->all());
    }

    public function testUnnestingEmpty(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);
        $qb = $qb->whereInUnnest('testId', []);
        $sql = $qb->toSql();
        $results = $qb->get('testId')->pluck('testId')->sort()->values();

        $this->assertSame('select * from `Test` where 0 = 1', $sql);
        $this->assertSame([], $results->all());
    }
}
