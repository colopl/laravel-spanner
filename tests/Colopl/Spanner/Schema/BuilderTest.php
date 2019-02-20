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
use Illuminate\Support\Str;

class BuilderTest extends TestCase
{
    private const TABLE_NAME_CREATED = 'schema_builder_test_table';
    private const TABLE_NAME_RELATION_PARENT = 'users';
    private const TABLE_NAME_RELATION_CHILD = 'user_items';
    private const TABLE_NAME_RELATION_PARENT_INTERLEAVED = 'users_interleaved';
    private const TABLE_NAME_RELATION_CHILD_INTERLEAVED = 'user_items_interleaved';
    private const TABLE_NAME_COMPOSITE_PRIMARY_KEY = 'composite_primary_key_test_table';
    private const TABLE_NAME_CONTAINS_ARRAY_TYPE_COLUMN = 'array_type_column_test_table';

    private const RUN_TEST_TRIGGER_ENV_VARIABLE_NAME = 'LARAVEL_SPANNER_RUN_SCHEMA_BUILDER_TESTS';

    /**
     */
    private function skipUnlessEnvVarSet()
    {
        if (getenv(self::RUN_TEST_TRIGGER_ENV_VARIABLE_NAME) === false) {
            $this->markTestSkipped('Skipping since this takes a long time。Please add the environment variable: '.self::RUN_TEST_TRIGGER_ENV_VARIABLE_NAME.' to enable this test');
        }
    }

    protected function setUp()
    {
        parent::setUp();
        $this->skipUnlessEnvVarSet();
    }

    /**
     * CAUTION: THIS TAKES A LONG TIME
     */
    public function testSchemaCreate()
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

    /**
     * CAUTION: THIS TAKES A LONG TIME
     */
    public function testSchemaDrop()
    {
        $conn = $this->getDefaultConnection();
        $sb = $conn->getSchemaBuilder();

        // NOTE: trying to delete a table that does not exist will not result in an error
        $sb->drop(self::TABLE_NAME_CREATED);
        $this->assertNotTrue($sb->hasTable(self::TABLE_NAME_CREATED));
    }

    public function testSchemaAlter()
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
        } catch (\Exception $e) {
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

    public function testCreateRelation()
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

    public function testCreateCompositePrimaryKeyTable()
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

    public function testCreateArrayColumnTable()
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

    public function testCreateInterleavedTable()
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
            $table->interleave(self::TABLE_NAME_RELATION_PARENT_INTERLEAVED)->onDelete('cascade');
        });

        $this->assertTrue($sb->hasTable(self::TABLE_NAME_RELATION_PARENT_INTERLEAVED));
        $this->assertTrue($sb->hasTable(self::TABLE_NAME_RELATION_CHILD_INTERLEAVED));
    }
}
