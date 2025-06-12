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

    public function testSelectArray(): void
    {
        $conn = $this->getDefaultConnection();
        $row = $conn->selectOne('SELECT [1, 2, 3] as numbers');

        $this->assertSame([1, 2, 3], $row['numbers']);
    }

    public function testSearchInArray(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_ARRAY_TEST;

        $testDataCount = 10;
        $insertValues = [];
        for ($i = 0; $i < $testDataCount; $i++) {
            $row = $this->generateArrayTestRow();
            $row['int64Array'] = [$i, $i + 1, $i + 2];
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

    public function testUpdateArray(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_ARRAY_TEST;

        $qb = $conn->table($tableName);

        $row = $this->generateArrayTestRow();
        $qb->insert($row);

        $insertedRow = $qb->where('arrayTestId', $row['arrayTestId'])->first();
        $this->assertSame($row['int64Array'], $insertedRow['int64Array']);

        $conn->table($tableName)
            ->where('arrayTestId', $row['arrayTestId'])
            ->update(['int64Array' => [4, 5, 6]]);

        $updatedRow = $conn->table($tableName)->where('arrayTestId', $row['arrayTestId'])->first();
        $this->assertSame([4, 5, 6], $updatedRow['int64Array']);
    }
}
