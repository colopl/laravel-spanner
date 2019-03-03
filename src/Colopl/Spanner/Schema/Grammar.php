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

use RuntimeException;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

class Grammar extends \Illuminate\Database\Schema\Grammars\Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var array
     */
    protected $modifiers = ['Nullable'];

    /**
     * @return string
     */
    public function compileTableExists()
    {
        return 'select * from information_schema.tables where table_schema = \'\' and table_name = ?';
    }

    /**
     * @return string
     */
    public function compileColumnListing()
    {
        return 'select column_name as `column_name` from information_schema.columns where table_schema = \'\' and table_name = ?';
    }

    /**
     * Compile the query to determine the list of indexes.
     *
     * @return string
     */
    public function compileIndexListing()
    {
        return 'select index_name as `index_name` from information_schema.indexes where table_schema = \'\' and table_name = ?';
    }

    /**
     * Compile a create table command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('create table %s (%s) %s%s',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            $this->addPrimaryKeys($blueprint),
            $this->addCluster($blueprint)
        );
    }

    /**
     * Compile an add column command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->prefixArray('add column', $this->getColumns($blueprint));

        return 'alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns);
    }

    /**
     * Compile a change column command into a series of SQL statements.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @param  Connection $connection
     * @return string
     *
     * @throws \RuntimeException
     */
    public function compileChange(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $columns = $this->prefixArray('alter column', $this->getChangedColumns($blueprint));

        return 'alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns);
    }

    /**
     * Compile a drop column command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->prefixArray('drop column', $this->wrapArray($command->columns));

        return 'alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns);
    }

    /**
     * Compile a unique key command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        $command->indexType = 'unique';
        return $this->compileIndex($blueprint, $command);
    }

    /**
     * Compile a plain index key command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#create_index
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        $columnsAsString = null;

        // if index is defined as assoc array, key is treated as column name and value as order
        // if index is defined as numeric array, then values are read as column names
        $keys = array_keys($command->columns);
        if (array_keys($keys) !== $keys) {
            $columns = [];
            foreach ($command->columns as $column => $order) {
                $columns[] = $this->wrap($column).' '.$order;
            }
            $columnsAsString = implode(', ', $columns);
        } else {
            $columnsAsString = $this->columnize($command->columns);
        }

        return sprintf('create %s%sindex %s on %s (%s)%s%s',
            empty($command->indexType) ? '' : trim($command->indexType).' ',
            empty($command->nullFiltered) ? '' :'null_filtered ',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $columnsAsString,
            $this->addStoringToIndex($command),
            $this->addInterleaveToIndex($command)
        );
    }

    /**
     * @param Fluent $indexCommand
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#create_index
     */
    protected function addInterleaveToIndex(Fluent $indexCommand): string
    {
        return empty($indexCommand->interleave) ? '' : ", interleave in {$this->wrap($indexCommand->interleave)}";
    }

    /**
     * @param Fluent $indexCommand
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#create_index
     */
    protected function addStoringToIndex(Fluent $indexCommand): string
    {
        if (empty($indexCommand->storing)) {
            return '';
        }
        $storings = [];
        foreach ($indexCommand->storing as $value) {
            $storings[] = $this->wrap($value);
        }
        return ' storing ('.implode(', ', $storings).')';
    }

    /**
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('drop index %s',
            $this->wrap($command->index)
        );
    }

    /**
     * Get the primary key syntax for a table creation statement.
     *
     * @param  Blueprint  $blueprint
     * @return string
     */
    protected function addPrimaryKeys(Blueprint $blueprint)
    {
        if (! is_null($primary = $this->getCommandByName($blueprint, 'primary'))) {
            return "primary key ({$this->columnize($primary->columns)})";
        }
        return '';
    }

    /**
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=ja#create_table
     * @param Blueprint $blueprint
     * @return string
     */
    protected function addCluster(Blueprint $blueprint)
    {
        if (! is_null($interleave = $this->getCommandByName($blueprint, 'interleave'))) {
            $cluster = ", interleave in parent {$this->wrap($interleave->parentTableName)}";
            if (! is_null($interleave->onDelete)) {
                $cluster .= " on delete {$interleave->onDelete}";
            }
            return $cluster;
        }
        return '';
    }

    /**
     * Compile a drop table command.
     * Note: you can't drop a table if there are indexes over it, or if there are any tables or indexes interleaved within it.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#drop_table
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDrop($blueprint, $command);
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeString(Fluent $column)
    {
        return "string({$column->length})";
    }

    /**
     * Create the column definition for a char type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeChar(Fluent $column)
    {
        return "string({$column->length})";
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeBinary(Fluent $column)
    {
        return "bytes({$column->length})";
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMediumInteger(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeInteger(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeSmallInteger(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for a float type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeFloat(Fluent $column)
    {
        return 'float64';
    }

    /**
     * Create the column definition for a double type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDouble(Fluent $column)
    {
        return 'float64';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDecimal(Fluent $column)
    {
        return 'float64';
    }

    /**
     * Create the column definition for a date type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDate(Fluent $column)
    {
        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDateTime(Fluent $column)
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a date-time (with timezone) type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDateTimeTz(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a time type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTime(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a time (with timezone) type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTimeTz(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column)
    {
        return 'timestamp';
    }

    /**
     * Create the column definition for a timestamp (with  timezone) type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTimestampTz(Fluent $column)
    {
        return 'timestamp';
    }

    /**
     * Create the column definition for a year type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeYear(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeUuid(Fluent $column)
    {
        return 'string(36)';
    }

    /**
     * Create the column definition for a ARRAY<T> type.
     * https://cloud.google.com/spanner/docs/arrays
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeArray(Fluent $column)
    {
        return "array<{$column->arrayType}>";
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeBoolean(Fluent $column)
    {
        return 'bool';
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeText(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMediumText(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeLongText(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeEnum(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a json type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeJson(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeJsonb(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for an ip address type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeIpAddress(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a mac address type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMacAddress(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a geometry type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeGeometry(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a point type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typePoint(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a line string type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeLineString(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a polygon type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typePolygon(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a geometry collection type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeGeometryCollection(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a multi point type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMultiPoint(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a multi line string type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMultiLineString(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a multi polygon type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMultiPolygon(Fluent $column)
    {
        return 'string';
    }

    /**
     * Create the column definition for a computed type.
     *
     * @param  Fluent  $column
     * @return string
     *
     * @throws \RuntimeException
     */
    protected function typeComputed(Fluent $column)
    {
        throw new RuntimeException('This database driver requires a type, see the virtualAs / storedAs modifiers.');
    }

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $column
     * @return string|null
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column)
    {
        if (is_null($column->virtualAs) && is_null($column->storedAs)) {
            return $column->nullable ? '' : ' not null';
        }
        return null;
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value !== '*') {
            return '`'.str_replace('`', '``', $value).'`';
        }

        return $value;
    }

    /**
     * Compile the blueprint's column definitions.
     *
     * @param  Blueprint $blueprint
     * @return array
     */
    protected function getChangedColumns(Blueprint $blueprint)
    {
        $columns = [];

        foreach ($blueprint->getChangedColumns() as $column) {
            // Each of the column types have their own compiler functions which are tasked
            // with turning the column definition into its SQL format for this platform
            // used by the connection. The column's modifiers are compiled and added.
            $sql = $this->wrap($column).' '.$this->getType($column);

            $columns[] = $this->addModifiers($sql, $blueprint, $column);
        }

        return $columns;
    }
}
