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

use Colopl\Spanner\Tests\TestCase;

class UnnestTest extends TestCase
{
    /** @see https://cloud.google.com/spanner/quotas#query_limits */
    public const SPANNER_PARAMETERS_LIMIT = 950;

    public function test_whereInUnnest(): void
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

    public function test_whereInUnnest__with_empty_values(): void
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

    public function test_whereInUnnest__with_more_than_950_parameters(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);
        $id1 = $this->generateUuid();
        $id2 = $this->generateUuid();
        $dummyIds = array_map($this->generateUuid(...), range(0, self::SPANNER_PARAMETERS_LIMIT));

        $qb->insert([['userId' => $id1, 'name' => 't1'], ['userId' => $id2, 'name' => 't2']]);
        $given = $qb->whereInUnnest('userId', [$id1, $id2, ...$dummyIds])->pluck('userId')->sort()->values()->all();
        $expected = collect([$id1, $id2])->sort()->values()->all();
        $this->assertSame($expected, $given);
    }


    public function test_whereNotInUnnest(): void
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

        $qb = $qb->whereNotInUnnest('testId', [$ids->first()]);
        $sql = $qb->toSql();
        $results = $qb->get('testId')->pluck('testId')->sort()->values();

        $this->assertSame('select * from `Test` where `testId` not in unnest(?)', $sql);
        $this->assertCount(2, $results);
        $this->assertSame($ids->skip(1)->values()->all(), $results->all());
    }

    public function test_substituteBindingsIntoRawSql(): void
    {
        $conn = $this->getDefaultConnection();
        $grammar = $conn->getQueryGrammar();
        $tableName = self::TABLE_NAME_USER;
        $keyName = 'userId';

        $normalId = $this->generateUuid();
        $unnestIds = array_map($this->generateUuid(...), range(0, self::SPANNER_PARAMETERS_LIMIT));

        foreach ([$normalId, ...$unnestIds] as $i => $id) {
            $conn->table($tableName)->insert([[$keyName => $id, 'name' => "user" . $i]]);
        }

        $conn->enableQueryLog();

        $conn->table($tableName)->where($keyName, $normalId)->delete();
        $conn->table($tableName)->whereIn($keyName, $unnestIds)->delete();

        $logs = $conn->getQueryLog();

        $this->assertCount(2, $logs);

        $logFirst = $logs[0];
        $logSecond = $logs[1];

        $this->assertSame(
            "delete from `{$tableName}` where `{$keyName}` = \"{$normalId}\"",
            $grammar->substituteBindingsIntoRawSql($logFirst['query'], $logFirst['bindings'])
        );

        $whereInIds = collect($unnestIds)->map(static fn($userId): string => "\"{$userId}\"")->join(', ');
        $this->assertSame(
            "delete from `{$tableName}` where `{$keyName}` in unnest([{$whereInIds}])",
            $grammar->substituteBindingsIntoRawSql($logSecond['query'], $logSecond['bindings'])
        );
    }
}
