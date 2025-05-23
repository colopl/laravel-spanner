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
use Colopl\Spanner\Connection;
use Illuminate\Database\Schema\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
     * @param array<string, scalar|null> $options
     * @return void
     */
    public function setDatabaseOptions(array $options): void
    {
        $connection = $this->connection;
        $name = Str::afterLast($connection->getDatabaseName(), '/');
        $line = implode(', ', Arr::map($options, fn($v, $k) => "$k = " . match (true) {
            is_null($v) => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_string($v) => $this->grammar->quoteString($v),
            default => $v,
        }));
        $connection->statement("ALTER DATABASE `{$name}` SET OPTIONS ({$line})");
    }

    /**
     * @deprecated Use Blueprint::dropIndex() instead. Will be removed in v10.0.
     *
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
     * @deprecated Use Blueprint::dropIndex() instead. Will be removed in v10.0.
     *
     * @param string $table
     * @param string $name
     * @return void
     */
    public function dropIndexIfExist($table, $name)
    {
        if (in_array($name, $this->getIndexListing($table), true)) {
            $blueprint = $this->createBlueprint($table);
            $blueprint->dropIndex($name);
            $this->build($blueprint);
        }
    }

    /**
     * @inheritDoc
     */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        return isset($this->resolver)
            ? ($this->resolver)($table, $callback)
            : new Blueprint($this->connection, $table, $callback);
    }

    /**
     * @inheritDoc
     */
    public function dropAllTables()
    {
        $connection = $this->connection;
        /** @var list<array{
         *     name: string,
         *     schema: string|null,
         *     schema_qualified_name: string,
         *     size: int|null,
         *     comment: string|null,
         *     collation: string|null,
         *     engine: string|null,
         *     parent: string|null
         * }> $tables
         */
        $tables = $this->getTables();

        if (count($tables) === 0) {
            return;
        }

        $sortedTables = [];

        // add parents counter
        foreach ($tables as $table) {
            $sortedTables[$table['name']] = ['parents' => 0, ...$table];
        }

        // loop through all tables and count how many parents they have
        foreach ($sortedTables as $key => $table) {
            if (!$table['parent']) {
                continue;
            }

            $current = $table;
            while ($current['parent']) {
                $table['parents'] += 1;
                $current = $sortedTables[$current['parent']];
            }
            $sortedTables[$key] = $table;
        }

        // sort tables desc based on parent count 
        usort($sortedTables, static fn($a, $b) => $b['parents'] <=> $a['parents']);

        // drop foreign keys first (otherwise index queries will include them)
        $queries = [];
        foreach ($sortedTables as $tableData) {
            $tableName = $tableData['name'];
            $foreigns = $this->getForeignKeys($tableName);
            $blueprint = $this->createBlueprint($tableName);
            foreach ($foreigns as $foreign) {
                $blueprint->dropForeign($foreign['name']);
            }
            array_push($queries, ...$blueprint->toSql());
        }
        /** @var Connection $connection */
        $connection->runDdlBatch($queries);

        // drop indexes and tables
        $queries = [];
        foreach ($sortedTables as $tableData) {
            $tableName = $tableData['name'];
            $indexes = $this->getIndexListing($tableName);
            $blueprint = $this->createBlueprint($tableName);
            foreach ($indexes as $index) {
                if ($index === 'PRIMARY_KEY') {
                    continue;
                }
                $blueprint->dropIndex($index);
            }
            $blueprint->drop();
            array_push($queries, ...$blueprint->toSql());
        }
        $connection->runDdlBatch($queries);
    }
}
