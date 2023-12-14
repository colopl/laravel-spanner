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

namespace Colopl\Spanner\Schema;

use Closure;
use Colopl\Spanner\Query\Processor;
use Colopl\Spanner\Connection;
use Illuminate\Database\Schema\Builder as BaseBuilder;
use Illuminate\Support\Fluent;

/**
 * @property Grammar $grammar
 */
class Builder extends BaseBuilder
{
    /**
     * The default binary length for migrations.
     *
     * @var int
     */
    public static $defaultBinaryLength = 255;


    /**
     * @deprecated Will be removed in a future Laravel version.
     *
     * @return list<array{ name: string, type: string }>
     */
    public function getAllTables()
    {
        return $this->connection->select(
            $this->grammar->compileGetAllTables()
        );
    }

    /**
     * @inheritDoc
     *
     * @return list<array{ name: string, type: string, parent: string }>
     */
    public function getTables()
    {
        return $this->connection->select(
            $this->grammar->compileTables()
        );
    }

    /**
     * @param string $table
     * @return string[]
     */
    public function getIndexListing($table)
    {
        $table = $this->connection->getTablePrefix().$table;

        $results = $this->connection->select(
            $this->grammar->compileIndexListing(), [$table]
        );

        /** @var Processor $processor */
        $processor = $this->connection->getPostProcessor();

        return $processor->processIndexListing($results);
    }

    /**
     * @param string $table
     * @return string[]
     */
    public function getForeignListing($table)
    {
        $table = $this->connection->getTablePrefix().$table;

        $results = $this->connection->select(
            $this->grammar->compileForeignListing(), [$table]
        );

        /** @var Processor $processor */
        $processor = $this->connection->getPostProcessor();

        return $processor->processIndexListing($results);
    }

    /**
     * @param string $table
     * @param string $name
     * @return void
     */
    public function dropIndex($table, $name)
    {
        $blueprint = $this->createBlueprint($table);
        $blueprint->dropIndex($name);
        $this->build($blueprint);
    }

    /**
     * @param string $table
     * @param string $name
     * @return void
     */
    public function dropIndexIfExist($table, $name)
    {
        if(in_array($name, $this->getIndexListing($table))) {
            $blueprint = $this->createBlueprint($table);
            $blueprint->dropIndex($name);
            $this->build($blueprint);
        }
    }

    /**
     * @inheritDoc
     */
    protected function createBlueprint($table, Closure $callback = null)
    {
        return isset($this->resolver)
            ? ($this->resolver)($table, $callback)
            : new Blueprint($table, $callback);
    }

    /**
     * Drop all tables from the database.
     *
     * @return void
     */
    public function dropAllTables()
    {
        /** @var Connection */
        $connection = $this->connection;
        $tables = self::getTables();
        $sortedTables = [];

        // get all tables
        foreach ($tables as $table) {
            $tableName = $table['name'];
            $parentTableName = $table['parent'];

            $sortedTables[$tableName] = [
                'name' => $tableName,
                'parent' => $parentTableName,
                'parents' => 0
            ];
        }

        // loop through all tables and count how many parents they have
        foreach ($sortedTables as $tableName => $tableData) {
            if(!$tableData['parent']) continue;

            $current = $tableData;
            while($current['parent']) {
                $tableData['parents'] += 1;
                $current = $sortedTables[$current['parent']];
            }
            $sortedTables[$tableName] = $tableData;
        }

        // sort tables desc based on parent count 
        usort($sortedTables, fn($a, $b) => $b['parents'] <=> $a['parents']);

        // drop foreign keys first (otherwise index queries will include them)
        $queries = [];
        foreach ($sortedTables as $tableData) {
            $tableName = $tableData['name'];
            $blueprint = new Blueprint($tableName);
            $foreigns = self::getForeignListing($tableName);
            foreach ($foreigns as $foreign) {
                $column = new Fluent();
                $column->index = $foreign;
                $queries[] = $this->grammar->compileDropForeign($blueprint, $column);
            }
        }
        $connection->runDdlBatch($queries);

        // drop indexes and tables
        $queries = [];
        foreach ($sortedTables as $tableData) {
            $tableName = $tableData['name'];
            $blueprint = new Blueprint($tableName);
            $indexes = self::getIndexListing($tableName);
            foreach ($indexes as $index) {
                if($index == 'PRIMARY_KEY') continue;
                $column = new Fluent();
                $column->index = $index;
                $queries [] = $this->grammar->compileDropIndex($blueprint, $column);
            }
            $queries[] = $this->grammar->compileDrop($blueprint, new Fluent());
        }
        $connection->runDdlBatch($queries);
    }
}
