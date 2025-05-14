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
use Colopl\Spanner\Tests\TestCase;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BuilderTestLast extends TestCase
{
    private const TABLE_NAME_CREATED = 'schema_builder_test_table';
    private const TABLE_NAME_RELATION_PARENT = 'users';
    private const TABLE_NAME_RELATION_CHILD = 'user_items';
    private const TABLE_NAME_RELATION_PARENT_INTERLEAVED = 'users_interleaved';
    private const TABLE_NAME_RELATION_CHILD_INTERLEAVED = 'user_items_interleaved';
    private const TABLE_NAME_COMPOSITE_PRIMARY_KEY = 'composite_primary_key_test_table';
    private const TABLE_NAME_CONTAINS_ARRAY_TYPE_COLUMN = 'array_type_column_test_table';

    public function testSchemaCreate(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        $sb->create(self::TABLE_NAME_CREATED, function (Blueprint $table) {
            $table->uuid('id');
            $table->string('name', 128);
            $table->integer('age');
            $table->dateTime('created_at');
            $table->primary('id');
        });

        $this->assertTrue($sb->hasTable(self::TABLE_NAME_CREATED));

        $columnNames = $sb->getColumnListing(self::TABLE_NAME_CREATED);
        $this->assertCount(0, collect(['id', 'name', 'age', 'created_at'])->diff($columnNames));
    }

    public function test_create_with_prefix(): void
    {
        config()->set('database.connections.main.prefix', 'test_');
        $conn = $this->getConnection('main');

        $sb = $conn->getSchemaBuilder();
        $table = $this->generateTableName(class_basename(__CLASS__));

        $sb->create($table, function (Blueprint $table) {
            $table->uuid('id')->primary();
        });
        $tables = array_map(static fn(array $row) => $row['name'], $sb->getTables());
        $this->assertContains('test_' . $table, $tables);
    }

    public function testSchemaDrop(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        // NOTE: trying to delete a table that does not exist will not result in an error
        $sb->drop(self::TABLE_NAME_CREATED);
        $this->assertNotTrue($sb->hasTable(self::TABLE_NAME_CREATED));
    }

    public function testSchemaAlter(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        $tableName = self::TABLE_NAME_CREATED . '_for_alter';

        try {
            $sb->create($tableName, function (Blueprint $table) {
                $table->uuid('id');
                $table->string('name', 128);
                $table->integer('age');
                $table->dateTime('created_at');
                $table->primary('id');
            });
        } catch (Exception $e) {
            if (!Str::contains($e->getMessage(), 'Duplicate name')) {
                throw $e;
            }
        }
        $this->assertTrue($sb->hasTable($tableName));

        $sb->table($tableName, function (Blueprint $table) {
            // NOTE: spanner only allows nullable columns to be added
            $table->string('description')->nullable();
        });
        $this->assertContains('description', $sb->getColumnListing($tableName));

        $sb->drop($tableName);
        $this->assertNotTrue($sb->hasTable($tableName));
    }

    public function testCreateRelation(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        $sb->create(self::TABLE_NAME_RELATION_PARENT, function (Blueprint $table) {
            $table->uuid('id');
            $table->string('token', 63);
            $table->string('name', 128);
            $table->timestamps();

            $table->unique('token');
            $table->primary('id');
        });

        $sb->create(self::TABLE_NAME_RELATION_CHILD, function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('user_id');
            $table->uuid('item_id');
            $table->integer('count');
            $table->timestamps();

            $table->primary('id');
            $table->index(['user_id', 'item_id']);
        });

        $this->assertTrue($sb->hasTable(self::TABLE_NAME_RELATION_PARENT));
        $this->assertTrue($sb->hasTable(self::TABLE_NAME_RELATION_CHILD));
    }

    public function testCreateCompositePrimaryKeyTable(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        $tableName = self::TABLE_NAME_COMPOSITE_PRIMARY_KEY;

        $sb->create($tableName, function (Blueprint $table) {
            $table->uuid('id');
            $table->integer('number');
            $table->string('name');

            $table->primary(['id', 'number']);
        });

        $this->assertTrue($sb->hasTable($tableName));
    }

    public function testCreateArrayColumnTable(): void
    {
        $tableName = self::TABLE_NAME_CONTAINS_ARRAY_TYPE_COLUMN;

        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $sb->create($tableName, function (Blueprint $table) {
            $table->uuid('id');
            $table->integerArray('int_array');
            $table->stringArray('string_array', '255');

            $table->primary('id');
        });

        $this->assertTrue($sb->hasTable($tableName));
    }

    public function testCreateInterleavedTable(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        $sb->create(self::TABLE_NAME_RELATION_PARENT_INTERLEAVED, function (Blueprint $table) {
            $table->uuid('user_id');
            $table->string('name');
            $table->timestamps();

            $table->primary('user_id');
        });

        $sb->create(self::TABLE_NAME_RELATION_CHILD_INTERLEAVED, function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('id');
            $table->uuid('item_id');
            $table->integer('count');
            $table->timestamps();

            $table->primary(['user_id', 'id']);
            $table->interleaveInParent(self::TABLE_NAME_RELATION_PARENT_INTERLEAVED)->cascadeOnDelete();
        });

        $this->assertTrue($sb->hasTable(self::TABLE_NAME_RELATION_PARENT_INTERLEAVED));
        $this->assertTrue($sb->hasTable(self::TABLE_NAME_RELATION_CHILD_INTERLEAVED));
    }

    public function test_getTables(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table = $this->generateTableName(class_basename(__CLASS__));

        $sb->create($table, function (Blueprint $table) {
            $table->uuid('id');
            $table->primary('id');
        });

        $row = Arr::first($sb->getTables(), fn ($row) => $row['name'] === $table);

        $this->assertSame([
            'name' => $table,
            'schema' => null,
            'parent' => null,
            'size' => null,
            'comment' => null,
            'collation' => null,
            'engine' => null,
        ], $row);
    }

    public function test_getColumns_with_nullable(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table = $this->generateTableName(class_basename(__CLASS__));

        $sb->create($table, function (Blueprint $table) {
            $table->integer('id')->nullable()->primary();
        });

        $this->assertSame([
            'name' => 'id',
            'type_name' => 'INT64',
            'type' => 'INT64',
            'collation' => null,
            'nullable' => true,
            'default' => null,
            'auto_increment' => false,
            'comment' => null,
            'generation' => null,
        ], Arr::first($sb->getColumns($table)));
    }

    public function test_getColumns_with_default(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table = $this->generateTableName(class_basename(__CLASS__));

        $sb->create($table, function (Blueprint $table) {
            $table->string('id', 1)->default('a')->primary();
        });

        $this->assertSame([
            'name' => 'id',
            'type_name' => 'STRING',
            'type' => 'STRING(1)',
            'collation' => null,
            'nullable' => false,
            'default' => '"a"',
            'auto_increment' => false,
            'comment' => null,
            'generation' => null,
        ], Arr::first($sb->getColumns($table)));
    }

    public function test_getTableListing(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table = $this->generateTableName(class_basename(__CLASS__));

        $sb->create($table, function (Blueprint $table) {
            $table->uuid('id');
            $table->primary('id');
        });

        $this->assertContains($table, $sb->getTableListing());
    }

    public function test_getIndexes(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table = $this->generateTableName(class_basename(__CLASS__));
        $sb->create($table, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('something');
            $table->index('something');
        });

        $this->assertSame([
            [
                'name' => strtolower($table) . '_something_index',
                'columns' => ['something'],
                'type' => 'index',
                'unique' => false,
                'primary' => false,
            ],
            [
                'name' => 'PRIMARY_KEY',
                'columns' => ['id'],
                'type' => 'primary_key',
                'unique' => true,
                'primary' => true,
            ],
        ], $sb->getIndexes($table));
    }

    public function test_getIndexListing(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table = $this->generateTableName(class_basename(__CLASS__));
        $sb->create($table, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('something');
            $table->index('something');
        });

        $this->assertSame([
            strtolower($table) . '_something_index',
            'PRIMARY_KEY',
        ], $sb->getIndexListing($table));
    }

    public function test_getForeignKeys(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table1 = $this->generateTableName(class_basename(__CLASS__). '_1');
        $sb->create($table1, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('something');
            $table->index('something');
        });

        $table2 = $this->generateTableName(class_basename(__CLASS__). '_2');
        $sb->create($table2, function (Blueprint $table) use ($table1) {
            $table->uuid('table2_id')->primary();
            $table->uuid('other_id');
            $table->index('other_id');
            $table->foreign('other_id')->references('id')->on($table1);
        });

        $this->assertSame([[
            'name' => strtolower($table2) . '_other_id_foreign',
            'columns' => ['other_id'],
            'foreign_schema' => '',
            'foreign_table' => $table1,
            'foreign_columns' => ['id'],
            'on_update' => "no action",
            'on_delete' => "no action",
        ]], $sb->getForeignKeys($table2));
    }

    public function test_dropAllTables(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();
        $table1 = $this->generateTableName(class_basename(__CLASS__));
        $sb->create($table1, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('something');
            $table->index('something');
        });

        $table2 = $this->generateTableName(class_basename(__CLASS__));
        $sb->create($table2, function (Blueprint $table) use ($table1) {
            $table->uuid('table2_id')->primary();
            $table->uuid('other_id');
            $table->index('other_id');
            $table->foreign('other_id')->references('id')->on($table1);
        });

        $table3 = $this->generateTableName(class_basename(__CLASS__));
        $sb->create($table3, function (Blueprint $table) use ($table2) {
            $table->uuid('table2_id');
            $table->uuid('table3_id');
            $table->primary(['table2_id', 'table3_id']);
            $table->interleaveInParent($table2);
        });

        $table4 = $this->generateTableName(class_basename(__CLASS__));
        $sb->create($table4, function (Blueprint $table) use ($table3) {
            $table->uuid('table2_id');
            $table->uuid('table3_id');
            $table->uuid('table4_id');
            $table->primary(['table2_id', 'table3_id', 'table4_id']);
            $table->interleaveInParent($table3);
        });

        $sb->dropAllTables();

        $tables = $sb->getTables();
        $this->assertEmpty($tables);
    }

    public function test_dropAllTables_when_no_tables_exist(): void
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        // All tables must be dropped before hand to ensure that this test will cover the "early return" case
        // for when there are no tables.
        $sb->dropAllTables();

        $tables = $sb->getTables();
        $this->assertEmpty($tables);

        $sb->dropAllTables();

        $tables = $sb->getTables();
        $this->assertEmpty($tables);
    }
}
