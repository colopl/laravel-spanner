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
            $table->interleave('User');
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
            $table->interleave('User')->onDelete('cascade');
        });
        $blueprint->create();

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create table `UserItem` (`id` string(36) not null, `userId` string(36) not null, `name` string(255) not null) primary key (`userId`), interleave in parent `User` on delete cascade',
            $queries[0]
        );
    }

    public function test_default_values(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('Test3', function (Blueprint $table) {
            $table->uuid('id');
            $table->integer('int')->default(1);
            $table->float('float')->default(0.1);
            $table->string('name')->default('abc');
            $table->integer('func')->default(DB::raw('POW(2, 2)'));
            $table->dateTime('started_at')->default(new Carbon('2022-01-01'));
            $table->dateTime('end_at')->useCurrent();
            $table->primary('id');
        });
        $blueprint->create();

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create table `Test3` (' .
            '`id` string(36) not null, ' .
            '`int` int64 not null default (1), ' .
            '`float` float64 not null default (0.1), ' .
            '`name` string(255) not null default (`abc`), ' .
            '`func` int64 not null default (POW(2, 2)), ' .
            '`started_at` timestamp not null default (`2022-01-01T00:00:00.000000+00:00`), ' .
            '`end_at` timestamp not null default (CURRENT_TIMESTAMP())' .
            ') primary key (`id`)',
            $queries[0]
        );

        $query = $conn->table('Test3');

        $query->insert([]);
    }

    public function testInterleaveIndex(): void
    {
        $conn = $this->getDefaultConnection();

        $blueprint = new Blueprint('UserItem', function (Blueprint $table) {
            $table->index(['userId', 'createdAt'])->interleave('User');
        });

        $queries = $blueprint->toSql($conn, new Grammar());
        $this->assertEquals(
            'create index `useritem_userid_createdat_index` on `UserItem` (`userId`, `createdAt`), interleave in `User`',
            $queries[0]
        );
    }

    public function testStoringIndex(): void
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
}
