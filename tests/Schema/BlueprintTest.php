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

namespace Colopl\Spanner\Tests\Schema;

use Colopl\Spanner\Schema\Blueprint;
use Colopl\Spanner\Schema\Grammar;
use Colopl\Spanner\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;

class BlueprintTest extends TestCase
{
    public function testCreateTable(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->uuid('id');
            $table->integer('int');
            $table->float('float');
            $table->string('name');
            $table->dateTime('started_at');
            $table->binary('blob');
            $table->timestamps();

            $table->primary('id');
        });
        $blueprint->create();

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create table `Test3` (`id` string(36) not null, `int` int64 not null, `float` float64 not null, `name` string(255) not null, `started_at` timestamp not null, `blob` bytes(255) not null, `created_at` timestamp, `updated_at` timestamp) primary key (`id`)',
            $queries[0]
        );
    }

    public function testDropTable(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->drop();
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'drop table `Test3`',
            $queries[0]
        );
    }

    public function testAddColumn(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->string('description', 255);
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'alter table `Test3` add column `description` string(255) not null',
            $queries[0]
        );
    }

    public function testChangeColumn(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->string('description', 512)->change();
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'alter table `Test3` alter column `description` string(512) not null',
            $queries[0]
        );
    }

    public function testDropColumn(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'alter table `Test3` drop column `description`',
            $queries[0]
        );
    }

    public function testCreateIndex(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->unique('name');
            $table->index('createdAt');
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            [
                'create unique index `test3_name_unique` on `Test3` (`name`)',
                'create index `test3_createdat_index` on `Test3` (`createdAt`)',
            ],
            $queries
        );
    }

    public function testDropIndex(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->dropUnique('test3_name_unique');
            $table->dropIndex('test3_createdat_index');
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            [
                'drop index `test3_name_unique`',
                'drop index `test3_createdat_index`',
            ],
            $queries
        );
    }

    public function test_no_primaryKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cloud Spanner require a primary key!');

        $blueprint = new Blueprint('test', function (Blueprint $table) {
            $table->create();
            $table->uuid('id');
        });
        $blueprint->toSql($this->getDefaultConnection(), new Grammar());
    }

    public function testCompositePrimaryKey(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('CompositePrimaryKeyTest', function (Blueprint $table) {
            $table->uuid('id');
            $table->integer('number');
            $table->string('name');

            $table->primary(['id', 'number']);
        });
        $blueprint->create();

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create table `CompositePrimaryKeyTest` (`id` string(36) not null, `number` int64 not null, `name` string(255) not null) primary key (`id`, `number`)',
            $queries[0]
        );
    }

    public function test_array_types(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('ArrayTypeTest', function (Blueprint $table) {
            $table->uuid('id');
            $table->integerArray('int_array')->nullable();
            $table->booleanArray('bool_array')->nullable();
            $table->floatArray('float_array')->nullable();
            $table->stringArray('string_array_undef')->nullable();
            $table->stringArray('string_array_1', 1)->nullable();
            $table->stringArray('string_array_max', 'max')->nullable();
            $table->timestampArray('timestamp_array')->nullable();
            $table->primary('id');
        });
        $blueprint->create();

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create table `ArrayTypeTest` (' .
            implode(', ', [
                '`id` string(36) not null',
                '`int_array` array<int64>',
                '`bool_array` array<bool>',
                '`float_array` array<float64>',
                '`string_array_undef` array<string(255)>',
                '`string_array_1` array<string(1)>',
                '`string_array_max` array<string(max)>',
                '`timestamp_array` array<timestamp>',
            ]) .
            ') primary key (`id`)',
            $queries[0]
        );
    }

    public function testInterleaveTable(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('UserItem', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('userId');
            $table->string('name');

            $table->primary('userId');
            $table->interleaveInParent('User');
        });
        $blueprint->create();

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create table `UserItem` (`id` string(36) not null, `userId` string(36) not null, `name` string(255) not null) primary key (`userId`), interleave in parent `User`',
            $queries[0]
        );

        $blueprint = new Blueprint('UserItem', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('userId');
            $table->string('name');

            $table->primary('userId');
            $table->interleaveInParent('User')->cascadeOnDelete();
        });
        $blueprint->create();

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create table `UserItem` (`id` string(36) not null, `userId` string(36) not null, `name` string(255) not null) primary key (`userId`), interleave in parent `User` on delete cascade',
            $queries[0]
        );
    }

    public function test_create_with_row_deletion_policy(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->useDefaultSchemaGrammar();
        $grammar = $conn->getSchemaGrammar();
        $table = 'Test_' . Str::random();

        $blueprint = new Blueprint($table, function (Blueprint $table) {
            $table->uuid('id');
            $table->primary('id');
            $table->dateTime('t')->nullable();
            $table->deleteRowsOlderThan('t', 100);
        });

        $blueprint->create();
        $blueprint->build($conn, $grammar);

        $statement = $blueprint->toSql($conn, $grammar)[0];

        self::assertEquals(
            "create table `{$table}` (`id` string(36) not null, `t` timestamp) primary key (`id`), row deletion policy (older_than(t, interval 100 day))",
            $statement,
        );
    }

    public function test_replace_row_deletion_policy(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->useDefaultSchemaGrammar();
        $grammar = $conn->getSchemaGrammar();
        $table = 'Test_' . Str::random();

        $blueprint1 = new Blueprint($table, function (Blueprint $table) {
            $table->create();
            $table->uuid('id');
            $table->primary('id');
            $table->dateTime('t')->nullable();
            $table->deleteRowsOlderThan('t', 100);
        });
        $blueprint1->build($conn, $grammar);

        $blueprint2 = new Blueprint($table, function (Blueprint $table) {
            $table->replaceRowDeletionPolicy('t', 200);
        });
        $blueprint2->build($conn, $grammar);

        $statement = $blueprint2->toSql($conn, $grammar)[0];

        self::assertEquals(
            "alter table `{$table}` replace row deletion policy (older_than(t, interval 200 day))",
            $statement,
        );
    }

    public function test_drop_row_deletion_policy(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->useDefaultSchemaGrammar();
        $grammar = $conn->getSchemaGrammar();
        $table = 'Test_' . Str::random();

        $blueprint1 = new Blueprint($table, function (Blueprint $table) {
            $table->create();
            $table->uuid('id');
            $table->primary('id');
            $table->dateTime('created_at')->nullable();
            $table->deleteRowsOlderThan('created_at', 100);
        });
        $blueprint1->build($conn, $grammar);

        $blueprint2 = new Blueprint($table, function (Blueprint $table) {
            $table->dropRowDeletionPolicy();
        });
        $blueprint2->build($conn, $grammar);

        $statement = $blueprint2->toSql($conn, $grammar)[0];

        self::assertEquals(
            "alter table `{$table}` drop row deletion policy",
            $statement,
        );
    }

    public function test_default_values(): void
    {
        $conn = $this->getDefaultConnection();
        $conn->useDefaultSchemaGrammar();
        $grammar = $conn->getSchemaGrammar();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->uuid('id');
            $table->integer('null')->default(null)->nullable();
            $table->integer('int')->default(1);
            $table->float('float')->default(0.1);
            $table->boolean('bool')->default(true);
            $table->string('string')->default('a');
            $table->float('raw')->default(DB::raw('1.1'));
            $table->date('date_as_string')->default('2022-01-01');
            $table->date('date_as_carbon')->default(new Carbon('2022-01-01'));
            $table->dateTime('time_as_string')->default('2022-01-01');
            $table->dateTime('time_as_carbon')->default(new Carbon('2022-01-01'));
            $table->dateTime('current_time')->useCurrent();
            $table->integerArray('int_array')->default([1, 2]);
            $table->booleanArray('bool_array')->default([false, true]);
            $table->floatArray('float_array')->default([2.2, 3.3]);
            $table->stringArray('string_array', 1)->default(['a', 'b']);
            $table->dateArray('date_array')->default(['2022-01-01']);
            $table->timestampArray('timestamp_array')->default(['2022-01-01']);
            $table->primary('id');
        });

        $blueprint->create();

        $queries = $blueprint->toSql($conn, $grammar);

        $this->assertEquals(
            'create table `Test3` (' . implode(', ', [
                '`id` string(36) not null',
                '`null` int64',
                '`int` int64 not null default (1)',
                '`float` float64 not null default (0.1)',
                '`bool` bool not null default (true)',
                '`string` string(255) not null default ("a")',
                '`raw` float64 not null default (1.1)',
                '`date_as_string` date not null default (DATE "2022-01-01")',
                '`date_as_carbon` date not null default (DATE "2022-01-01")',
                '`time_as_string` timestamp not null default (TIMESTAMP "2022-01-01T00:00:00.000000+00:00")',
                '`time_as_carbon` timestamp not null default (TIMESTAMP "2022-01-01T00:00:00.000000+00:00")',
                '`current_time` timestamp not null default (CURRENT_TIMESTAMP())',
                '`int_array` array<int64> not null default ([1, 2])',
                '`bool_array` array<bool> not null default ([false, true])',
                '`float_array` array<float64> not null default ([2.2, 3.3])',
                '`string_array` array<string(1)> not null default (["a", "b"])',
                '`date_array` array<date> not null default ([DATE "2022-01-01"])',
                '`timestamp_array` array<timestamp> not null default ([TIMESTAMP "2022-01-01T00:00:00.000000+00:00"])',
            ]) . ') primary key (`id`)',
            $queries[0]
        );

        $blueprint->build($conn, $grammar);

        $query = $conn->table('Test3');

        $query->insert(['id' => Uuid::uuid4()->toString()]);

        /** @var array<string, mixed> $result */
        $result = $query->sole();

        self::assertSame(null, $result['null']);
        self::assertSame(1, $result['int']);
        self::assertSame(0.1, $result['float']);
        self::assertSame(true, $result['bool']);
        self::assertSame('a', $result['string']);
        self::assertSame(1.1, $result['raw']);
        self::assertSame('2022-01-01T00:00:00.000000+00:00', $result['date_as_string']->get()->format($grammar->getDateFormat()));
        self::assertSame('2022-01-01T00:00:00.000000+00:00', $result['date_as_carbon']->get()->format($grammar->getDateFormat()));
        self::assertSame('2022-01-01T00:00:00.000000+00:00', $result['time_as_string']->format($grammar->getDateFormat()));
        self::assertSame('2022-01-01T00:00:00.000000+00:00', $result['time_as_carbon']->format($grammar->getDateFormat()));
        self::assertInstanceOf(Carbon::class, $result['current_time']);
        self::assertSame([1, 2], $result['int_array']);
        self::assertSame([false, true], $result['bool_array']);
        self::assertSame([2.2, 3.3], $result['float_array']);
        self::assertSame(['a', 'b'], $result['string_array']);
    }

    public function test_index_with_interleave(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('UserItem', function (Blueprint $table) {
            $table->index(['userId', 'createdAt'])->interleaveIn('User');
            $table->index(['userId', 'updatedAt'])->interleave('User');
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals([
                'create index `useritem_userid_createdat_index` on `UserItem` (`userId`, `createdAt`), interleave in `User`',
                'create index `useritem_userid_updatedat_index` on `UserItem` (`userId`, `updatedAt`), interleave in `User`',
            ],
            $queries
        );
    }

    public function test_index_with_storing(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('UserItem', function (Blueprint $table) {
            $table->index(['userId', 'createdAt'])->storing(['itemId', 'count']);
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create index `useritem_userid_createdat_index` on `UserItem` (`userId`, `createdAt`) storing (`itemId`, `count`)',
            $queries[0]
        );
    }

    public function test_null_filtered_index(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('UserItem', function (Blueprint $table) {
            $table->index(['userId'])->nullFiltered();
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create null_filtered index `useritem_userid_index` on `UserItem` (`userId`)',
            $queries[0]
        );
    }
}
