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

class SpannerArrayTest extends TestCase
{
    protected const TEST_DB_REQUIRED = true;

    protected function generateArrayTestRow(): array
    {
        return [
            /**
             *
             * NOTE: we want an array type at top because we have an overwritten method where the parent used `reset($values)` to determine if it's a dataSet or a single data
             * @see https://github.com/laravel/framework/blob/5.5/src/Illuminate/Database/Query/Builder.php#L2122
             */
            'int64Array' => [1, 2, 3, 4, 5],
            'arrayTestId' => $this->generateUuid(),
        ];
    }

    public function testSelectArray()
    {
        $conn = $this->getDefaultConnection();
        $row = $conn->selectOne('SELECT [1, 2, 3] as numbers');

        $this->assertEquals([1, 2, 3], $row['numbers']);
    }

    public function testSearchInArray()
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_ARRAY_TEST;

        $testDataCount = 10;
        $insertValues = [];
        for ($i = 0; $i < $testDataCount; $i++) {
            $row = $this->generateArrayTestRow();
            $row['int64Array'] = [$i, $i+1, $i+2];
            $insertValues[] = $row;
        }
        $qb = $conn->table($tableName);
        $qb->insert($insertValues);

        $qb = $conn->table($tableName);
        $result = $qb->whereInArray('int64Array', 0)->get();
        $this->assertCount(1, $result);

        $qb = $conn->table($tableName);
        $result = $qb->whereInArray('int64Array', 1)->get();
        $this->assertCount(2, $result);

        $qb = $conn->table($tableName);
        $result = $qb->whereInArray('int64Array', 2)->get();
        $this->assertCount(3, $result);

        $qb = $conn->table($tableName);
        $result = $qb->whereInArray('int64Array', 300)->get();
        $this->assertCount(0, $result);
    }

    public function testInsertArray()
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_ARRAY_TEST;

        $qb = $conn->table($tableName);

        $row = $this->generateArrayTestRow();
        $qb->insert($row);

        $insertedRow = $qb->where('arrayTestId', $row['arrayTestId'])->first();
        $this->assertEquals($row['int64Array'], $insertedRow['int64Array']);

        // empty array
        $row = $this->generateArrayTestRow();
        $row['int64Array'] = [];
        $conn->table($tableName)->insert($row);
    }

    public function testInsertArrayArray()
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_ARRAY_TEST;

        $qb = $conn->table($tableName);

        $rows = [
            $this->generateArrayTestRow(),
            $this->generateArrayTestRow(),
            $this->generateArrayTestRow(),
        ];

        $qb->insert($rows);

        foreach ($rows as $row) {
            $qb = $conn->table($tableName);
            $insertedRow = $qb->where('arrayTestId', $row['arrayTestId'])->first();
            $this->assertEquals($row['int64Array'], $insertedRow['int64Array']);
        }
    }

    public function testUpdateArray()
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_ARRAY_TEST;

        $qb = $conn->table($tableName);

        $row = $this->generateArrayTestRow();
        $qb->insert($row);

        $insertedRow = $qb->where('arrayTestId', $row['arrayTestId'])->first();
        $this->assertEquals($row['int64Array'], $insertedRow['int64Array']);

        $conn->table($tableName)
            ->where('arrayTestId', $row['arrayTestId'])
            ->update(['int64Array' => [4, 5, 6]]);

        $updatedRow = $conn->table($tableName)->where('arrayTestId', $row['arrayTestId'])->first();
        $this->assertEquals([4, 5, 6], $updatedRow['int64Array']);
    }
}
