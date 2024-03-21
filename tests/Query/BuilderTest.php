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

use BadMethodCallException;
use Colopl\Spanner\Query\Builder;
use Colopl\Spanner\Schema\Blueprint;
use Colopl\Spanner\Tests\TestCase;
use Colopl\Spanner\TimestampBound\ExactStaleness;
use Google\Cloud\Core\Exception\ConflictException;
use Google\Cloud\Spanner\Bytes;
use Google\Cloud\Spanner\Duration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use LogicException;

use const Grpc\STATUS_ALREADY_EXISTS;

class BuilderTest extends TestCase
{
    public function test_insert_single_row(): void
    {
        $conn = $this->getDefaultConnection();

        $table = $this->createTempTable(function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->integer('int');
            $blueprint->float('float');
            $blueprint->boolean('bool');
            $blueprint->string('string');
            $blueprint->integerArray('array_int');
            $blueprint->floatArray('array_float');
            $blueprint->booleanArray('array_bool');
            $blueprint->stringArray('array_string');
            $blueprint->timestampArray('array_timestamp');
        });

        $id = $this->generateUuid();

        $return = $conn->query()->from($table)->insert([
            'id' => $id,
            'int' => 1,
            'float' => 1.1,
            'bool' => true,
            'string' => 'test',
            'array_int' => [-1, 0],
            'array_float' => [1.1, 2.2],
            'array_bool' => [true, false],
            'array_string' => ['t1', 't2'],
            'array_timestamp' => [
                new Carbon('2000-01-01 00:00:00 Asia/Tokyo'),
                new Carbon('2001-01-01 00:00:00 Asia/Tokyo'),
            ],
        ]);

        $this->assertTrue($return);

        $row = $conn->table($table)
            ->where('id', $id)
            ->sole();

        $this->assertSame(1, $row['int']);
        $this->assertSame(1.1, $row['float']);
        $this->assertSame(true, $row['bool']);
        $this->assertSame('test', $row['string']);
        $this->assertSame([-1, 0], $row['array_int']);
        $this->assertSame([1.1, 2.2], $row['array_float']);
        $this->assertSame([true, false], $row['array_bool']);
        $this->assertSame(['t1', 't2'], $row['array_string']);
        $this->assertSame($row['array_timestamp'][0]->format('Y-m-d H:i:s P'), '1999-12-31 15:00:00 +00:00');
        $this->assertSame($row['array_timestamp'][1]->format('Y-m-d H:i:s P'), '2000-12-31 15:00:00 +00:00');
    }

    public function test_insert_many_rows(): void
    {
        $table = $this->createTempTable(function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->integerArray('array_int');
        });

        $conn = $this->getDefaultConnection();

        $id1 = $this->generateUuid();
        $id2 = $this->generateUuid();

        $return = $conn->query()->from($table)->insert([
            ['id' => $id1, 'array_int' => [1, 2]],
            ['id' => $id2, 'array_int' => [3, 4]],
        ]);

        $this->assertTrue($return);

        $row = $conn->table($table)->where('id', $id1)->sole();
        $this->assertSame($row['id'], $id1);
        $this->assertSame($row['array_int'], [1, 2]);

        $row = $conn->table($table)->where('id', $id2)->sole();
        $this->assertSame($row['id'], $id2);
        $this->assertSame($row['array_int'], [3, 4]);
    }

    public function test_insert_same_primary_key_throws_error(): void
    {
        $conn = $this->getDefaultConnection();

        $table = $this->createTempTable(function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->string('name');
        });

        $row = ['id' => $this->generateUuid(), 'name' => 'test'];

        $return = $conn->table($table)->insert($row);
        $this->assertTrue($return);

        // should not be able to insert the same thing twice
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(STATUS_ALREADY_EXISTS);
        $conn->table($table)->insert($row);
    }

    public function testUpdate(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => '[laravel-spanner] phpunit testInsert()',
        ];

        // updating nothing
        $affectedRowCount = $conn->table($tableName)
            ->where('userId', $insertRow['userId'])
            ->update(['name' => 'test']);
        $this->assertSame(0, $affectedRowCount);

        $res = $conn->table($tableName)
            ->insert($insertRow);
        $this->assertTrue($res);

        $afterName = 'changed by testUpdate()';
        $conn->table($tableName)
            ->where('userId', $insertRow['userId'])
            ->update(['name' => $afterName]);

        $afterRow = $conn->table($tableName)
            ->where('userId', $insertRow['userId'])
            ->first();

        $this->assertSame($afterName, $afterRow['name']);
    }

    public function testDelete(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => '[laravel-spanner] phpunit testDelete()',
        ];

        $res = $conn->table($tableName)
            ->insert($insertRow);
        $this->assertTrue($res);

        $conn->table($tableName)
            ->where('userId', $insertRow['userId'])
            ->delete();

        $insertedRow = $conn->table($tableName)
            ->where('userId', $insertRow['userId'])
            ->first();
        $this->assertNull($insertedRow);
    }

    public function testCompositePrimaryKeyTest(): void
    {
        $conn = $this->getDefaultConnection();

        $user = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];
        $conn->table(self::TABLE_NAME_USER)->insert($user);
        $this->assertDatabaseHas(self::TABLE_NAME_USER, $user);

        $tableName = self::TABLE_NAME_USER_ITEM;

        $userItems = [
            [
                'userId' => $user['userId'],
                'userItemId' => $this->generateUuid(),
                'itemId' => $this->generateUuid(),
                'count' => 1,
            ],
            [
                'userId' => $user['userId'],
                'userItemId' => $this->generateUuid(),
                'itemId' => $this->generateUuid(),
                'count' => 2,
            ],
        ];

        $conn->table($tableName)->insert($userItems);

        $this->assertDatabaseHas($tableName, $userItems[0]);
        $this->assertDatabaseHas($tableName, $userItems[1]);

        $resultRows = $conn->table($tableName)
            ->where('userId', $userItems[1]['userId'])
            ->where('count', $userItems[1]['count'])
            ->get();

        $this->assertCount(1, $resultRows);
        $this->assertSame($userItems[1]['userItemId'], $resultRows[0]['userItemId']);

        // requires all interleave keys for delete
        $conn->table($tableName)
            ->where('userId', $userItems[0]['userId'])
            ->where('userItemId', $userItems[0]['userItemId'])
            ->delete();

        $this->assertDatabaseMissing($tableName, $userItems[0]);
    }

    public function testCountRows(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $this->assertSame(0, $qb->count());

        $insertValues = [];
        for ($i = 0; $i < 100; $i++) {
            $insertValues[] = [
                'userId' => $this->generateUuid(),
                'name' => 'test' . $i,
            ];
        }
        $qb->insert($insertValues);

        $this->assertSame(100, $qb->count());
        $this->assertSame(100, $qb->count('userId'));
    }

    public function testAggregate(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $testDataCount = 100;
        $insertValues = [];
        for ($i = 0; $i < $testDataCount; $i++) {
            $val = $this->generateTestRow();
            $val['intTest'] = $i;
            $insertValues[] = $val;
        }
        $qb->insert($insertValues);

        $this->assertEqualsWithDelta(49.5, $qb->average('intTest'), 0.0001);
        $this->assertSame(4950, $qb->sum('intTest'));
        $this->assertSame(0, $qb->min('intTest'));
        $this->assertSame(99, $qb->max('intTest'));
    }

    public function testOrderBy(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $testDataCount = 100;
        $insertValues = [];
        for ($i = 0; $i < $testDataCount; $i++) {
            $val = $this->generateTestRow();
            $val['intTest'] = $i;
            $insertValues[] = $val;
        }
        $qb->insert($insertValues);

        $qb->orderByDesc('intTest');
        $this->assertSame(sprintf('select * from `%s` order by `intTest` desc', $tableName), $qb->toSql());
        $this->assertSame(99, $qb->first()['intTest']);
    }

    public function testExistsSubquery(): void
    {
        $conn = $this->getDefaultConnection();
        $tableNameParent = self::TABLE_NAME_USER;
        $tableNameChild = self::TABLE_NAME_USER_ITEM;

        $userId1 = $this->generateUuid();
        $userId2 = $this->generateUuid();
        $conn->table($tableNameParent)->insert([
            ['userId' => $userId1, 'name' => 'testuser1'],
            ['userId' => $userId2, 'name' => 'testuser2'],
        ]);

        $conn->table($tableNameChild)->insert([
            ['userId' => $userId1, 'userItemId' => $this->generateUuid(), 'itemId' => $this->generateUuid(), 'count' => 10],
        ]);

        $qb = $conn->table($tableNameParent)->whereExists(function (Builder $query) use ($tableNameParent, $tableNameChild) {
            $query->selectRaw(1)
                ->from($tableNameChild)
                ->whereRaw("{$tableNameChild}.userId = {$tableNameParent}.userId");
        });

        $this->assertSame(
            sprintf('select * from `%s` where exists (select 1 from `%s` where %s.userId = %s.userId)',
                $tableNameParent, $tableNameChild, $tableNameChild, $tableNameParent),
            $qb->toSql());
        $this->assertCount(1, $qb->get());
        $this->assertSame($userId1, $qb->get()->first()['userId']);
    }

    public function test_groupBy(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $insertValues = [
            array_merge($this->generateTestRow(), ['stringTest' => 'test1', 'intTest' => 20]),
            array_merge($this->generateTestRow(), ['stringTest' => 'test1', 'intTest' => 20]),
            array_merge($this->generateTestRow(), ['stringTest' => 'test2', 'intTest' => 20]),
            array_merge($this->generateTestRow(), ['stringTest' => 'test2', 'intTest' => 40]),
        ];
        $qb->insert($insertValues);

        $qb = $conn->table($tableName);
        $this->assertSame(
            ['test1' => ['stringTest' => 'test1', 'cnt' => 2], 'test2' => ['stringTest' => 'test2', 'cnt' => 2]],
            $qb->groupBy('stringTest')
                ->selectRaw('stringTest, count(*) as cnt')
                ->get()
                ->keyBy('stringTest')
                ->sort()
                ->all()
        );

        $qb = $conn->table($tableName);
        $this->assertSame(
            [20 => ['intTest' => 20, 'cnt' => 3], 40 => ['intTest' => 40, 'cnt' => 1]],
            $qb->groupBy('intTest')
            ->selectRaw('intTest, count(*) as cnt')
            ->get()
            ->keyBy('intTest')
            ->sort()
            ->all()
        );

        // HAVING
        $qb = $conn->table($tableName);
        $this->assertSame(
            [40 => ['intTest' => 40, 'cnt' => 1]],
            $qb->groupBy('intTest')
                ->having('intTest', '>', 20)
                ->selectRaw('intTest, count(*) as cnt')
                ->get()
                ->keyBy('intTest')
                ->all()
        );
    }

    public function testJoin(): void
    {
        $conn = $this->getDefaultConnection();
        $tableNameParent = self::TABLE_NAME_USER;
        $tableNameChild = self::TABLE_NAME_USER_ITEM;

        $userId1 = $this->generateUuid();
        $userId2 = $this->generateUuid();
        $conn->table($tableNameParent)->insert([
            ['userId' => $userId1, 'name' => 'testuser1'],
            ['userId' => $userId2, 'name' => 'testuser2'],
        ]);

        $conn->table($tableNameChild)->insert([
            ['userId' => $userId1, 'userItemId' => $this->generateUuid(), 'itemId' => $this->generateUuid(), 'count' => 10],
        ]);

        $users = $conn->table($tableNameParent)
            ->join($tableNameChild, "{$tableNameParent}.userId", '=', "{$tableNameChild}.userId")
            ->select("{$tableNameParent}.*", "{$tableNameChild}.itemId", "{$tableNameChild}.count")
            ->get();
        $this->assertCount(1, $users);
        $this->assertSame($userId1, $users->first()['userId']);
        $this->assertSame(10, $users->first()['count']);
    }

    public function testPaginate(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $testDataCount = 100;
        $insertValues = [];
        for ($i = 0; $i < $testDataCount; $i++) {
            $insertValues[] = ['userId' => $this->generateUuid(), 'name' => 'test' . $i];
        }
        $qb->insert($insertValues);

        $pagination = $conn->table($tableName)->paginate(2);
        $this->assertSame(50, $pagination->lastPage());
        $this->assertSame(100, $pagination->total());
    }

    public function test_forceIndex(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $this->assertSame('select * from `User`', $qb->toSql());

        $qb->forceIndex('test_index_name');
        $this->assertSame('select * from `User` @{FORCE_INDEX=test_index_name}', $qb->toSql());

        $qb->forceIndex('test_index_name2');
        $this->assertSame('select * from `User` @{FORCE_INDEX=test_index_name2}', $qb->toSql());

        $qb->forceIndex(null);
        $this->assertSame('select * from `User`', $qb->toSql());

        $this->assertInstanceOf(Builder::class, $qb->forceIndex(null));
    }

    public function test_disableEmulatorNullFilteredIndexCheck(): void
    {
        $conn = $this->getDefaultConnection();

        $tableName = $this->createTempTable(function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->string('name');
            $blueprint->index(['name'], 'test_index_name')->nullFiltered();
        });

        $qb = $conn->table($tableName)
            ->forceIndex('test_index_name')
            ->disableEmulatorNullFilteredIndexCheck();

        $hint = '@{FORCE_INDEX=test_index_name,spanner_emulator.disable_query_null_filtered_index_check=true}';
        $this->assertSame("select * from `{$tableName}` {$hint}", $qb->toSql());
        $this->assertSame([], $qb->get()->all());
    }

    public function test_disableEmulatorNullFilteredIndexCheck_without_calling_force_index(): void
    {
        $this->expectExceptionMessage('Force index must be set before disabling null filter index check');
        $this->expectException(LogicException::class);

        $conn = $this->getDefaultConnection();
        $conn->table('Test')->disableEmulatorNullFilteredIndexCheck();
    }

    public function test_useIndex(): void
    {
        $this->expectExceptionMessage('Cloud Spanner does not support index type: hint');
        $this->expectException(BadMethodCallException::class);

        $this->getDefaultConnection()
            ->table(self::TABLE_NAME_USER)
            ->useIndex('test_index_name2')
            ->toSql();
    }

    public function test_ignoreIndex(): void
    {
        $this->expectExceptionMessage('Cloud Spanner does not support index type: ignore');
        $this->expectException(BadMethodCallException::class);

        $this->getDefaultConnection()
            ->table(self::TABLE_NAME_USER)
            ->ignoreIndex('test_index_name2')
            ->toSql();
    }

    public function testInterleaveTable(): void
    {
        $conn = $this->getDefaultConnection();

        $parentTableName = self::TABLE_NAME_USER;
        $parentUser = ['userId' => $this->generateUuid(), 'name' => 'test'];
        $conn->table($parentTableName)->insert($parentUser);
        $this->assertDatabaseHas($parentTableName, $parentUser);

        $childTableName = self::TABLE_NAME_USER_ITEM;

        $childUserItems = [
            [
                'userId' => $parentUser['userId'],
                'userItemId' => $this->generateUuid(),
                'itemId' => $this->generateUuid(),
                'count' => 99,
            ],
            [
                'userId' => $parentUser['userId'],
                'userItemId' => $this->generateUuid(),
                'itemId' => $this->generateUuid(),
                'count' => 99,
            ],
        ];

        $conn->table($childTableName)->insert($childUserItems);

        $this->assertDatabaseHas($childTableName, $childUserItems[0]);
        $this->assertDatabaseHas($childTableName, $childUserItems[1]);

        $resultRows = $conn->table($childTableName)
            ->where('userId', $childUserItems[1]['userId'])
            ->where('userItemId', $childUserItems[1]['userItemId'])
            ->get();

        $this->assertCount(1, $resultRows);
        $this->assertSame($childUserItems[1]['count'], $resultRows[0]['count']);

        $conn->table($childTableName)
            ->where('userId', $childUserItems[0]['userId'])
            ->where('userItemId', $childUserItems[0]['userItemId'])
            ->delete();

        $this->assertDatabaseMissing($childTableName, $childUserItems[0]);
    }

    public function test_insert_numeric_types(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $row = $this->generateTestRow();
        $qb->insert($row);

        $insertedRow = $qb->get()->first();
        $numeric = $insertedRow['numericTest'];
        $this->assertSame('123.456', $numeric);
        $this->assertNull($insertedRow['nullableNumericTest']);
    }

    public function testInsertDatetime(): void
    {
        date_default_timezone_set('Asia/Tokyo');

        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $row = $this->generateTestRow();
        $carbonMax = Carbon::maxValue();
        $row['timestampTest'] = $carbonMax;
        $qb->insert($row);

        $insertedRow = $qb->get()->first();
        /** @var Carbon $insertedTimestamp */
        $insertedTimestamp = $insertedRow['timestampTest'];
        $this->assertSame($carbonMax->getTimestamp(), $insertedTimestamp->getTimestamp());
    }

    public function test_upsert_single_row(): void
    {
        $table = __FUNCTION__;
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $sb->create($table, function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('s', 1);
        });

        $query = $conn->table($table);
        $this->assertSame(1, $query->upsert(['id' => 1, 's' => 'a']));
        $this->assertSame(1, $query->upsert(['id' => 1, 's' => 'b']));

        $this->assertSame(['id' => 1, 's' => 'b'], (array) $query->sole());
    }

    public function test_upsert_multi_row(): void
    {
        $table = __FUNCTION__;
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $sb->create($table, function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('s', 1);
        });

        $query = $conn->table($table);

        // insert
        $this->assertSame(2, $query->upsert([
            ['id' => 1, 's' => 'a'],
            ['id' => 2, 's' => 'b'],
        ]));

        // update (no change x1, change x1, insert x1)
        $this->assertSame(3, $query->upsert([
            ['id' => 1, 's' => 'a'],
            ['id' => 2, 's' => '_'],
            ['id' => 3, 's' => 'c'],
        ]));

        $this->assertSame(['a', '_', 'c'], $query->orderBy('id')->pluck('s')->all());
    }

    public function test_upsert_throw_error(): void
    {
        $table = __FUNCTION__;
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $sb->create($table, function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('s', 1);
        });

        $query = $conn->table($table);
        $exceptionThrown = false;
        try {
            $query->upsert([
                ['id' => 1, 's' => 'a'],
                ['id' => 2, 's' => 'bb'],
            ]);
        } catch (QueryException $e) {
            $this->assertSame(9, $e->getCode());
            $this->assertStringContainsString('New value exceeds the maximum size limit', $e->getMessage());
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);
        $this->assertSame(0, $query->count());
    }

    public function testWhereDatetime(): void
    {
        date_default_timezone_set('Asia/Tokyo');

        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $row = $this->generateTestRow();
        $carbonMax = Carbon::maxValue();
        $row['timestampTest'] = $carbonMax;
        $qb->insert($row);

        $this->assertSame(1, $qb->where('timestampTest', '=', Carbon::maxValue())->count());
        $this->assertSame(1, $qb->where('timestampTest', '<=', Carbon::maxValue())->count());
        $this->assertSame(0, $qb->where('timestampTest', '<', Carbon::maxValue())->count());
    }

    /**
     * null ではない列を null で上書きできるか
     */
    public function testUpdateWithNull(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;

        $insertRow = $this->generateTestRow();
        $insertRow['nullableStringTest'] = 'hello';

        $res = $conn->table($tableName)
            ->insert($insertRow);
        $this->assertTrue($res);

        $insertedRow = $conn->table($tableName)
            ->where('testId', $insertRow['testId'])
            ->first();

        $this->assertSame('hello', $insertedRow['nullableStringTest']);

        $conn->table($tableName)
            ->where('testId', $insertRow['testId'])
            ->update(['nullableStringTest' => null]);

        $afterRow = $conn->table($tableName)
            ->where('testId', $insertRow['testId'])
            ->first();

        $this->assertSame(null, $afterRow['nullableStringTest']);
    }

    public function testUpdateOrInsert(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;

        $row = $this->generateTestRow();
        $row['stringTest'] = 'row1';

        $row1exists = $conn->table($tableName)->where('testId', $row['testId'])->exists();
        $this->assertFalse($row1exists);

        $res = $conn->table($tableName)->updateOrInsert(['testId' => $row['testId']], $row);
        $this->assertTrue($res);

        $row1exists = $conn->table($tableName)->where('testId', $row['testId'])->exists();
        $this->assertTrue($row1exists);

        $row['stringTest'] = 'updated';
        $res2 = $conn->table($tableName)->updateOrInsert(['testId' => $row['testId']], $row);
        $this->assertTrue($res2);

        $record = (array) $conn->table($tableName)->where('testId', $row['testId'])->first();
        $this->assertSame('updated', $record['stringTest']);
    }

    public function testDeleteOnCascase(): void
    {
        $conn = $this->getDefaultConnection();

        $user = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];
        $conn->table(self::TABLE_NAME_USER)->insert($user);
        $this->assertDatabaseHas(self::TABLE_NAME_USER, $user);

        $tableName = self::TABLE_NAME_USER_ITEM;

        $userItems = [
            [
                'userId' => $user['userId'],
                'userItemId' => $this->generateUuid(),
                'itemId' => $this->generateUuid(),
                'count' => 1,
            ],
            [
                'userId' => $user['userId'],
                'userItemId' => $this->generateUuid(),
                'itemId' => $this->generateUuid(),
                'count' => 2,
            ],
        ];

        $conn->table($tableName)->insert($userItems);

        $this->assertDatabaseHas($tableName, $userItems[0]);
        $this->assertDatabaseHas($tableName, $userItems[1]);

        // children should be deleted along with parent
        $conn->table(self::TABLE_NAME_USER)
            ->where('userId', $user['userId'])
            ->delete();

        $this->assertDatabaseMissing(self::TABLE_NAME_USER, $user);
        $this->assertDatabaseMissing($tableName, $userItems[0]);
        $this->assertDatabaseMissing($tableName, $userItems[1]);
    }

    public function testInsertBytes(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $row = $this->generateTestRow();
        $bytes = new Bytes("\x00\x01\x02");
        $row['bytesTest'] = $bytes;
        $qb->insert($row);

        $insertedRow = $qb->first();
        /** @var Bytes $insertedBytes */
        $insertedBytes = $insertedRow['bytesTest'];
        $this->assertSame($bytes->formatAsString(), $insertedBytes->formatAsString());
    }

    public function testWhereBytes(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $row = $this->generateTestRow();
        $bytes = new Bytes("\x00\x01\x02");
        $row['bytesTest'] = $bytes;
        $qb->insert($row);

        $this->assertSame(1, $qb->where('bytesTest', '=', $bytes)->count());
        $this->assertSame(0, $qb->where('bytesTest', '=', new Bytes("\x00\x01"))->count());
    }

    public function testWhereIn(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $qb = $conn->table($tableName);

        $insertValues = [];
        foreach ([10, 20, 30, 40] as $num) {
            $insertValues[] = array_merge($this->generateTestRow(), [
                'stringTest' => 'test1',
                'intTest' => $num,
                'bytesTest' => new Bytes(chr($num)),
            ]);
        }
        $qb->insert($insertValues);

        $this->assertSame(2, $conn->table($tableName)->whereIn('intTest', [10, 20])->count());
        $this->assertSame(0, $conn->table($tableName)->whereIn('intTest', [50])->count());
        $this->assertSame(2, $conn->table($tableName)->whereIn('bytesTest', [new Bytes(chr(10)), new Bytes(chr(20))])->count());
    }

    public function test_partitionedDml(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_TEST;
        $insertCount = 100;

        $insertValues = [];
        for ($i = 0; $i < $insertCount; $i++) {
            $insertValues[] = $this->generateTestRow();
        }
        $conn->table($tableName)->insert($insertValues);

        $this->assertSame($insertCount, $conn->table($tableName)
            ->where('stringTest', 'test')
            ->partitionedUpdate(['stringTest' => 'test2']));

        $this->assertSame(0, $conn->table($tableName)
            ->where('stringTest', 'test')
            ->count());
        $this->assertSame($insertCount, $conn->table($tableName)
            ->where('stringTest', 'test2')
            ->count());

        $this->assertSame($insertCount, $conn->table($tableName)
            ->where('stringTest', 'test2')
            ->partitionedDelete());
        $this->assertSame(0, $conn->table($tableName)
            ->where('stringTest', 'test2')
            ->count());
    }

    public function test_whereLike(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;

        $qb = $conn->table($tableName);

        $this->assertSame(0, $qb->count());

        $insertValues = [];
        for ($i = 0; $i < 100; $i++) {
            $insertValues[] = [
                'userId' => $this->generateUuid(),
                'name' => '%'.$i,
            ];
        }
        $qb->insert($insertValues);

        // all rows start with % so there should be more than 100
        $this->assertSame(100, $conn->table($tableName)->where('name', 'like', '\%%')->count());

        // if % is escaped, its treated as a normal string so it should return no results
        $this->assertSame(0, $conn->table($tableName)->where('name', 'like', '\%\%')->count());

        // since names are formatted from %0 to %99, it should return rows (0, 10, 20, 30, 40, 50, 60, 70, 80, 90) for a total of 10
        $this->assertSame(10, $conn->table($tableName)->where('name', 'like', '%0')->count());

        // STARTS_WITH should return the same result as using %
        $this->assertSame(100, $conn->table($tableName)->whereRaw("STARTS_WITH(`name`, '%')")->count());

        $injectionParam = mb_convert_encoding('%表 UNION ALL SELECT 1', 'Shift_JIS');
        $caughtException = null;
        try {
            $this->assertSame(0, $conn->table($tableName)->where('name', 'like', $injectionParam)->count());
        } catch (QueryException $ex) {
            $caughtException = $ex;
        }
        if (getenv('SPANNER_EMULATOR_HOST')) {
            $this->assertStringContainsString('INTERNAL', $caughtException?->getMessage());
        } else {
            $this->assertStringContainsString('Invalid UTF-8', $caughtException?->getMessage());
        }
    }

    public function testEscapeCharacter(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;

        $qb = $conn->table($tableName);

        $this->assertSame(0, $qb->count());

        $escapeChars = ["\n", "%\n", "\r"];

        $insertValues = [];
        foreach ($escapeChars as $ec) {
            $insertValues[] = [
                'userId' => $this->generateUuid(),
                'name' => $ec,
            ];
        }
        $qb->insert($insertValues);

        foreach ($escapeChars as $ec) {
            $this->assertSame(1, $conn->table($tableName)->where('name', $ec)->count());
        }
    }

    public function testStaleReads(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;
        $qb = $conn->table($tableName);

        $insertData = ['userId' => $this->generateUuid(), 'name' => 'first'];
        $qb->insert($insertData);
        $this->assertDatabaseHas($tableName, $insertData);

        $stalenessRow = $qb->withStaleness(new ExactStaleness(new Duration(60)))
            ->first();
        $this->assertEmpty($stalenessRow);
    }

    public function testTruncate(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;
        $query = $conn->table($tableName);

        $insertData = ['userId' => $this->generateUuid(), 'name' => 'first'];
        $query->insert($insertData);
        $this->assertDatabaseHas(self::TABLE_NAME_USER, $insertData);

        $query->truncate();

        $this->assertDatabaseCount($tableName, 0);
    }

    public function test_insertGetId(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cloud Spanner does not support insertGetId');

        $conn = $this->getDefaultConnection();
        $qb = $conn->table(self::TABLE_NAME_USER);
        $qb->insertGetId(['userId' => $this->generateUuid(), 'name' => 'first']);
    }

    public function test_lock(): void
    {
        $conn = $this->getDefaultConnection();
        $qb = $conn->table(self::TABLE_NAME_USER);
        $sql = $qb->lock()->toRawSql();
        $this->assertSame('select * from `User`', $sql);
    }

    public function test_lockForUpdate(): void
    {
        $conn = $this->getDefaultConnection();
        $qb = $conn->table(self::TABLE_NAME_USER);
        $sql = $qb->lockForUpdate()->toRawSql();
        $this->assertSame('select * from `User`', $sql);
    }

    public function test_sharedLock(): void
    {
        $conn = $this->getDefaultConnection();
        $qb = $conn->table(self::TABLE_NAME_USER);
        $sql = $qb->sharedLock()->toRawSql();
        $this->assertSame('select * from `User`', $sql);
    }

    public function test_prefixing(): void
    {
        config()->set('database.connections.main.prefix', 'test_');
        $conn = $this->getConnection('main');
        $this->assertSame('select * from `test_User`', $conn->table('User')->toRawSql());
    }

    public function test_toRawSql(): void
    {
        $table = 'RawSqlTest';
        $conn = $this->getDefaultConnection();

        $sql = $conn->table($table)->where('b', true)->toRawSql();
        $this->assertSame('select * from `RawSqlTest` where `b` = true', $sql, 'true');

        $sql = $conn->table($table)->where('b', false)->toRawSql();
        $this->assertSame('select * from `RawSqlTest` where `b` = false', $sql, 'false');

        $sql = $conn->table($table)->where('i', 1)->toRawSql();
        $this->assertSame('select * from `RawSqlTest` where `i` = 1', $sql, 'text');

        $sql = $conn->table($table)->where('f', 1.1)->toRawSql();
        $this->assertSame('select * from `RawSqlTest` where `f` = 1.1', $sql, 'text');

        $sql = $conn->table($table)->where('s', 'test')->toRawSql();
        $this->assertSame('select * from `RawSqlTest` where `s` = "test"', $sql, 'text');

        $sql = $conn->table($table)->where('s', '"tes\'s"')->toRawSql();
        $this->assertSame('select * from `RawSqlTest` where `s` = "\"tes\'s\""', $sql, 'escaped');

        $sql = $conn->table($table)->where('s', "tes\nt")->toRawSql();
        $this->assertSame("select * from `RawSqlTest` where `s` = r\"\"\"tes\nt\"\"\"", $sql, 'newline');

        $sql = $conn->table($table)->where('s', "t\"e\"s\nt")->toRawSql();
        $this->assertSame("select * from `RawSqlTest` where `s` = r\"\"\"t\\\"e\\\"s\nt\"\"\"", $sql, 'newline with escaped quote');

        $sql = $conn->table($table)->where('s', new Carbon('2024-02-21 00:00:00'))->toRawSql();
        $this->assertSame('select * from `RawSqlTest` where `s` = "2024-02-21T00:00:00.000000Z"', $sql, 'Carbon');
    }

    public function test_dataBoost_enabled(): void
    {
        $conn = $this->getDefaultConnection();
        $tableName = self::TABLE_NAME_USER;

        $conn->table($tableName)->insert(['userId' => $this->generateUuid(), 'name' => __FUNCTION__]);

        $query = $conn->table($tableName)->useDataBoost();
        $result = $query->get();

        $this->assertTrue($query->dataBoostEnabled());
        $this->assertSame(1, $result->count());
        $this->assertSame(__FUNCTION__, $result->first()['name']);
    }

    public function test_dataBoost_disabled(): void
    {
        $query = $this->getDefaultConnection()->table('t')->useDataBoost(false);
        $this->assertFalse($query->dataBoostEnabled());
    }
}
