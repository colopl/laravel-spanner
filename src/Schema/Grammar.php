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

use Colopl\Spanner\Concerns\SharedGrammarCalls;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar as BaseGrammar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class Grammar extends BaseGrammar
{
    use SharedGrammarCalls;

    /**
     * @inheritdoc
     */
    protected $modifiers = ['Nullable', 'Default'];

    public function compileTableExists(): string
    {
        return 'select * from information_schema.tables where table_schema = \'\' and table_name = ?';
    }

    public function compileGetAllTables(): string
    {
        return 'select `table_name` as name, `table_type` as type from information_schema.tables where table_schema = \'\' and table_type = \'BASE TABLE\'';
    }

    public function compileColumnListing(): string
    {
        return 'select column_name as `column_name` from information_schema.columns where table_schema = \'\' and table_name = ?';
    }

    /**
     * Compile the query to determine the list of indexes.
     */
    public function compileIndexListing(): string
    {
        return 'select index_name as `index_name` from information_schema.indexes where table_schema = \'\' and table_name = ?';
    }

    /**
     * Compile a create table command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent<string, mixed> $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('create table %s (%s) %s%s%s',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            $this->addPrimaryKeys($blueprint),
            $this->addInterleaveToTable($blueprint),
            $this->addRowDeletionPolicy($blueprint)
        );
    }

    /**
     * Compile an add column command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent<string, mixed> $command
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
     * @param  Fluent<string, mixed> $command
     * @param  Connection $connection
     * @return string[]
     */
    public function compileChange(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $columns = $this->prefixArray('alter column', $this->getChangedColumns($blueprint));

        return ['alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns)];
    }

    /**
     * Compile a drop column command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent<string, mixed> $command
     * @return string
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->prefixArray('drop column', $this->wrapArray($command->columns));

        return 'alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns);
    }

    /**
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed> $command
     * @return string
     */
    public function compileReplaceRowDeletionPolicy(Blueprint $blueprint, Fluent $command)
    {
        if ($command->policy !== 'olderThan') {
            throw new RuntimeException('Unknown deletion policy:'.$command->policy);
        }
        return "alter table {$this->wrapTable($blueprint)} replace row deletion policy (older_than({$command->column}, interval {$command->days} day))";
    }

    /**
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed> $command
     * @return string
     */
    public function compileDropRowDeletionPolicy(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop row deletion policy';
    }

    /**
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=ja#create_table
     * @param Blueprint $blueprint
     * @return string
     */
    protected function addInterleaveToTable(Blueprint $blueprint)
    {
        if (! is_null($command = $this->getCommandByName($blueprint, 'interleaveInParent'))) {
            $schema = ", interleave in parent {$this->wrap($command->table)}";
            if (! is_null($command->onDelete)) {
                $schema .= " on delete {$command->onDelete}";
            }
            return $schema;
        }
        return '';
    }

    /**
     * @see https://cloud.google.com/spanner/docs/ttl#defining_a_row_deletion_policy
     * @param Blueprint $blueprint
     * @return string
     */
    protected function addRowDeletionPolicy(Blueprint $blueprint)
    {
        if (! is_null($command = $this->getCommandByName($blueprint, 'rowDeletionPolicy'))) {
            if ($command->policy === 'olderThan') {
                return ', row deletion policy (older_than('.$command->column.', interval '.$command->days.' day))';
            }
            throw new RuntimeException('Unknown deletion policy:'.$command->policy);
        }
        return '';
    }

    /**
     * Compile a unique key command.
     *
     * @param  Blueprint  $blueprint
     * @param  IndexDefinition $command
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
     * @param Blueprint  $blueprint
     * @param IndexDefinition $command
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
            empty($command->indexType) ? '' : trim((string) $command->indexType).' ',
            empty($command->nullFiltered) ? '' :'null_filtered ',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $columnsAsString,
            $this->addStoringToIndex($command),
            $this->addInterleaveToIndex($command)
        );
    }

    /**
     * @param IndexDefinition $indexCommand
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#create_index
     */
    protected function addInterleaveToIndex(Fluent $indexCommand): string
    {
        return empty($indexCommand->interleaveIn) ? '' : ", interleave in {$this->wrap($indexCommand->interleaveIn)}";
    }

    /**
     * @param Fluent<string, mixed> $indexCommand
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
     * @param Fluent<string, mixed> $command
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
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed> $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDropIndex($blueprint, $command);
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
        throw new LogicException('Cloud Spanner require a primary key!');
    }

    /**
     * Compile a drop table command.
     * Note: you can't drop a table if there are indexes over it, or if there are any tables or indexes interleaved within it.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent<string, mixed> $command
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
     * @param  Fluent<string, mixed> $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDrop($blueprint, $command);
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeString(Fluent $column)
    {
        return "string({$column->length})";
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeText(Fluent $column)
    {
        return "string(max)";
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeBinary(Fluent $column)
    {
        return "bytes({$column->length})";
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeInteger(Fluent $column)
    {
        return $this->typeBigInteger($column);
    }

    /**
     * Create the column definition for a float type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeFloat(Fluent $column)
    {
        return $this->typeDouble($column);
    }

    /**
     * Create the column definition for a double type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeDouble(Fluent $column)
    {
        return 'float64';
    }

    /**
     * Create the column definition for a date type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeDate(Fluent $column)
    {
        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeDateTime(Fluent $column)
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param  Fluent<string, mixed>  $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column)
    {
        if ($column->useCurrent) {
            $column->default(new Expression('CURRENT_TIMESTAMP()'));
        }

        return 'timestamp';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param  Fluent<string, mixed> $column
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
     * @param Fluent<string, mixed> $column
     * @return string
     */
    protected function typeArray(Fluent $column)
    {
        return 'array<' . $this->getArrayInnerType($column) . '>';
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param  Fluent<string, mixed> $column
     * @return string
     */
    protected function typeBoolean(Fluent $column)
    {
        return 'bool';
    }

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent<string, mixed> $column
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        if (is_null($column->virtualAs) && is_null($column->storedAs)) {
            return $column->nullable ? '' : ' not null';
        }
        return null;
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function getArrayInnerType(Fluent $column): string
    {
        return $this->{'type'.ucfirst((string) $column->arrayType)}($column);
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed> $column
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        $value = $column->default;

        if (is_null($value)) {
            return null;
        }

        return ' default (' . $this->formatDefaultValue($column, $this->getType($column), $value) . ')';
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatDefaultValue(Fluent $column, string $type, mixed $value): int|float|string
    {
        if ($value instanceof ExpressionContract) {
            return $value->getValue($this);
        }

        // Match type without length or subtype.
        return match (Str::match('/^\w+/', $type)) {
            'array' => $this->formatArrayValue($column, $value),
            'bool' => $this->formatBoolValue($column, $value),
            'date' => $this->formatDateValue($column, $value),
            'float64' => $this->formatFloatValue($column, $value),
            'int64' => $this->formatIntValue($column, $value),
            'string' => $this->formatStringValue($column, $value),
            'timestamp' => $this->formatTimestampValue($column, $value),
            default => throw new LogicException('Unsupported default for ' . $type . ' column: ' . $column->toJson()),
        };
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatArrayValue(Fluent $column, mixed $value): string
    {
        assert(is_array($value));
        $list = [];
        foreach ($value as $each) {
            $list[] = $this->formatDefaultValue($column, $this->getArrayInnerType($column), $each);
        }
        return '[' . implode(', ', $list) . ']';
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatBoolValue(Fluent $column, mixed $value): string
    {
        assert(is_bool($value));
        return $value ? 'true' : 'false';
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatDateValue(Fluent $column, mixed $value): string
    {
        assert(is_string($value) || $value instanceof DateTimeInterface);
        if (is_string($value)) {
            $value = Carbon::parse($value);
        }
        return 'DATE "' . $value->format('Y-m-d') . '"';
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatFloatValue(Fluent $column, mixed $value): string
    {
        assert(is_float($value));
        return (string)$value;
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatIntValue(Fluent $column, mixed $value): string
    {
        assert(is_int($value));
        return (string)$value;
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatStringValue(Fluent $column, mixed $value): string
    {
        assert(is_string($value));
        return '"' . $value . '"';
    }

    /**
     * @param Fluent<string, mixed> $column
     */
    protected function formatTimestampValue(Fluent $column, mixed $value): string
    {
        assert(is_string($value) || $value instanceof DateTimeInterface);
        if (is_string($value)) {
            $value = Carbon::parse($value);
        }
        return 'TIMESTAMP "' . $value->format($this->getDateFormat()) . '"';
    }

    /**
     * Compile the blueprint's column definitions.
     *
     * @param  Blueprint $blueprint
     * @return array<int, string>
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
