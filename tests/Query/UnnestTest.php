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

use Colopl\Spanner\Query\Builder as QueryBuilder;
use Colopl\Spanner\Query\Uuids;
use Colopl\Spanner\Schema\Blueprint;
use Colopl\Spanner\Tests\TestCase;
use Illuminate\Database\QueryException;

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

    /**
     * @return string the name of a table whose primary key uses the native UUID type
     */
    private function createNativeUuidTable(): string
    {
        return $this->createTempTable(function (Blueprint $table) {
            $table->nativeUuid('id')->primary();
            $table->string('name');
        });
    }

    public function test_whereInUnnest__with_native_uuid_column(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = $this->createNativeUuidTable();
        $qb = $conn->table($tableName);

        $id1 = $this->generateUuid();
        $id2 = $this->generateUuid();
        $qb->insert([['id' => $id1, 'name' => 't1'], ['id' => $id2, 'name' => 't2']]);

        $given = $qb->whereInUnnest('id', Uuids::from([$id1]))->pluck('id')->all();

        $this->assertSame([$id1], $given);
    }

    public function test_whereInUnnest__with_native_uuid_column_and_unmarked_strings(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = $this->createNativeUuidTable();
        $qb = $conn->table($tableName);

        $id = $this->generateUuid();
        $qb->insert([['id' => $id, 'name' => 't1']]);

        // ARRAY<STRING> is not coerced to ARRAY<UUID>, unlike a single STRING binding.
        // Uuids::from() exists to work around this.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/IN UNNEST for argument types: UUID, ARRAY<STRING>/');

        $qb->whereInUnnest('id', [$id])->get();
    }

    public function test_whereIn__with_native_uuid_column_across_unnest_threshold(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = $this->createNativeUuidTable();
        $qb = $conn->table($tableName);

        $id = $this->generateUuid();
        $qb->insert([['id' => $id, 'name' => 't1']]);

        $threshold = $conn->getConfig('parameter_unnest_threshold')
            ?? QueryBuilder::DEFAULT_UNNEST_THRESHOLD;

        // whereIn() silently switches to UNNEST() once the threshold is crossed, so
        // marked values have to work on both sides of it.
        $dummyIds = array_map($this->generateUuid(...), range(1, $threshold + 1));

        $below = $conn->table($tableName)->whereIn('id', Uuids::from([$id]))->pluck('id')->all();
        $above = $conn->table($tableName)->whereIn('id', Uuids::from([$id, ...$dummyIds]))->pluck('id')->all();

        $this->assertSame([$id], $below);
        $this->assertSame([$id], $above);
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
}
