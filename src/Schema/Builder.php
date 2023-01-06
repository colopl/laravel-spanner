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
use Illuminate\Database\Schema\Builder as BaseBuilder;

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
     * @return list<string>
     */
    public function getAllTables()
    {
        /** @var array{ TABLE_NAME: string } $results */
        $results = $this->connection->select(
            $this->grammar->compileGetAllTables()
        );

        /** @var Processor $processor */
        $processor = $this->connection->getPostProcessor();

        return $processor->processGetAllTables($results);
    }

    /**
     * @inheritDoc
     */
    public function getColumnListing($table)
    {
        $table = $this->connection->getTablePrefix().$table;

        $results = $this->connection->select(
            $this->grammar->compileColumnListing(), [$table]
        );

        return $this->connection->getPostProcessor()->processColumnListing($results);
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
            ? call_user_func($this->resolver, $table, $callback)
            : new Blueprint($table, $callback);
    }
}
