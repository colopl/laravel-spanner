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
     * The default relationship morph key type.
     *
     * @var string
     */
    public static $defaultMorphKeyType = 'uuid';
    
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
     * @inheritDoc Adds a parent key, for tracking interleaving
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
     * @deprecated Use getIndexes($table) instead
     * 
     * @param string $table
     * @return string[]
     */
    public function getIndexListing($table)
    {
        return parent::getIndexes($table);
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
        if(in_array($name, $this->getIndexes($table))) {
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
        $tables = $this->getTables();
        $sortedTables = [];

        // add parents counter
        foreach ($tables as $table) {
           $sortedTables[$table['name']] = ['parents' => 0, ...$table];
        }

        // loop through all tables and count how many parents they have
        foreach ($sortedTables as $key => $table) {
            if(!$table['parent']) continue;

            $current = $table;
            while($current['parent']) {
                $table['parents'] += 1;
                $current = $sortedTables[$current['parent']];
            }
            $sortedTables[$key] = $table;
        }

        // sort tables desc based on parent count 
        usort($sortedTables, fn($a, $b) => $b['parents'] <=> $a['parents']);

        // drop foreign keys first (otherwise index queries will include them)
        $queries = [];
        foreach ($sortedTables as $tableData) {
            $tableName = $tableData['name'];
            $foreigns = $this->getForeignKeys($tableName);
            $blueprint = $this->createBlueprint($tableName);
            foreach ($foreigns as $foreign) {
                $blueprint->dropForeign($foreign);
            }
            array_push($queries, ...$blueprint->toSql($connection, $this->grammar));
        }
        $connection->runDdlBatch($queries);

        // drop indexes and tables
        $queries = [];
        foreach ($sortedTables as $tableData) {
            $tableName = $tableData['name'];
            $indexes = $this->getIndexes($tableName);
            $blueprint = $this->createBlueprint($tableName);
            foreach ($indexes as $index) {
                if($index == 'PRIMARY_KEY') continue;
                $blueprint->dropIndex($index);
            }
            $blueprint->drop();
            array_push($queries, ...$blueprint->toSql($connection, $this->grammar));
        }
        $connection->runDdlBatch($queries);
    }
}
