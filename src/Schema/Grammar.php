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
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
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
    protected $modifiers = ['Nullable', 'Default', 'GeneratedAs', 'Invisible', 'UseSequence'];

    /**
     * Compile the query to determine the tables.
     *
     * @return string
     */
    public function compileTables()
    {
        return implode(' ', [
            'select',
            implode(', ', [
                'table_name as name',
                'table_schema as `schema`',
                'parent_table_name as parent',
            ]),
            'from information_schema.tables',
            'where table_type = \'BASE TABLE\'',
            'and table_schema = \'\'',
        ]);
    }

    /**
     * Compile the query to determine the columns.
     *
     * @param string $table
     * @return string
     */
    public function compileColumns($table)
    {
        return implode(' ', [
            'select',
            implode(', ', [
                'column_name as `name`',
                'spanner_type as `type`',
                'is_nullable as `nullable`',
                'column_default as `default`',
            ]),
            'from information_schema.columns',
            'where table_name = ' . $this->quoteString($table),
        ]);
    }

    /**
     * Compile the query to determine the list of indexes.
     *
     * @param string $table
     * @return string
     */
    public function compileIndexes($table)
    {
        return implode(' ', [
            'select',
            implode(', ', [
                'i.index_name as `name`',
                'string_agg(c.column_name, \',\') as `columns`',
                'i.index_type as `type`',
                'i.is_unique as `unique`',
            ]),
            'from information_schema.indexes as i',
            'join information_schema.index_columns as c on i.table_schema = c.table_schema and i.table_name = c.table_name and i.index_name = c.index_name',
            'where i.table_schema = ' . $this->quoteString(''),
            'and i.table_name = ' . $this->quoteString($table),
            'group by i.index_name, i.index_type, i.is_unique',
        ]);
    }

    /**
     * Compile the query to determine the list of foreign keys.
     *
     * @param string $table
     * @return string
     */
    public function compileForeignKeys($table)
    {
        return implode(' ', [
            'select',
            implode(', ', [
                'kc.constraint_name as `name`',
                'string_agg(kc.column_name) as `columns`',
                'cc.table_schema as `foreign_schema`',
                'cc.table_name as `foreign_table`',
                'string_agg(cc.column_name) as `foreign_columns`',
                'rc.update_rule as `on_update`',
                'rc.delete_rule as `on_delete`',
            ]),
            'from information_schema.key_column_usage kc',
            'join information_schema.referential_constraints rc on kc.constraint_name = rc.constraint_name',
            'join information_schema.constraint_column_usage cc on kc.constraint_name = cc.constraint_name',
            'where kc.table_schema = ""',
            'and kc.table_name = ' . $this->quoteString($table),
            'group by kc.constraint_name, cc.table_schema, cc.table_name, rc.update_rule, rc.delete_rule'
        ]);
    }

    /**
     * Compile a create table command.
     *
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed> $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('create table %s (%s) %s%s%s',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            $this->addPrimaryKeys($blueprint),
            $this->addInterleaveToTable($blueprint),
            $this->addRowDeletionPolicy($blueprint),
        );
    }

    /**
     * Compile an add column command.
     *
     * @param Blueprint  $blueprint
     * @param Fluent<string, mixed>&object{ column: ColumnDefinition } $command
     * @return string
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        $column = $command->column;

        $sql = sprintf('alter table %s add column %s %s',
            $this->wrapTable($blueprint),
            $this->wrap($column),
            $this->getType($column),
        );

        return $this->addModifiers($sql, $blueprint, $column);
    }

    /**
     * Compile a change column command into a series of SQL statements.
     *
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed>&object{ column: ColumnDefinition } $command
     * @param Connection $connection
     * @return string
     */
    public function compileChange(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $column = $command->column;

        $sql = sprintf('alter table %s alter column %s %s',
            $this->wrapTable($blueprint),
            $this->wrap($column),
            $this->getType($column),
        );

        return $this->addModifiers($sql, $blueprint, $column);
    }

    /**
     * Compile a drop column command.
     *
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed>&object{ columns: list<string> } $command
     * @return string[]
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        return $this->prefixArray(
            'alter table ' . $this->wrapTable($blueprint) . ' drop column',
            $this->wrapArray($command->columns),
        );
    }

    /**
     * Compile a fulltext index key command.
     *
     * @param Blueprint $blueprint
     * @param SearchIndexDefinition $command
     * @return string
     */
    public function compileFullText(Blueprint $blueprint, Fluent $command): string
    {
        $schema = sprintf('create search index %s on %s(%s)',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $this->columnize($command->columns),
        );

        $schema .= $this->addStoringToIndex($command);

        $partitionBy = (array) $command->partitionBy;
        if (count($partitionBy) > 0) {
            $schema .= ' partition by ' . $this->columnize($partitionBy);
        }

        if (isset($command->orderBy)) {
            $schema .= ' order by ';
            foreach ($command->orderBy as $column => $order) {
                $schema .= is_string($column)
                    ? $this->wrap($column) . ' ' . $order
                    : $this->wrap($order);
            }
        }

        $schema .= $this->addInterleaveToIndex($command);

        $schema .= $command->getOptions() !== []
            ? ' options (' . $this->formatOptions($command->getOptions()) . ')'
            : '';

        return $schema;
    }

    /**
     * Compile a drop fulltext index command.
     *
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed>&object{ index: string } $command
     * @return string
     */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('drop search index %s',
            $this->wrap($command->index),
        );
    }

    /**
     * @param Blueprint $blueprint
     * @param RenameDefinition $command
     * @return string
     */
    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        $from = $this->wrapTable($blueprint);
        $to = $this->wrapTable($command->to);
        $schema = "alter table {$from} rename to {$to}";
        if (isset($command->synonym)) {
            $synonym = is_string($command->synonym)
                ? $this->wrapTable($command->synonym)
                : $from;
            $schema .= ", add synonym {$synonym}";
        }
        return $schema;
    }

    /**
     * @param Blueprint $blueprint
     * @param RenameDefinition $command
     * @return string
     */
    public function compileAddSynonym(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);
        $synonym = $this->wrapTable($command->synonym);
        return "alter table {$table} add synonym {$synonym}";
    }

    /**
     * @param Blueprint $blueprint
     * @param RenameDefinition $command
     * @return string
     */
    public function compileDropSynonym(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);
        $synonym = $this->wrapTable($command->synonym);
        return "alter table {$table} drop synonym {$synonym}";
    }

    /**
     * @param Blueprint $blueprint
     * @param RowDeletionPolicyDefinition $command
     * @return string
     */
    public function compileAddRowDeletionPolicy(Blueprint $blueprint, Fluent $command)
    {
        if ($command->policy !== 'olderThan') {
            throw new RuntimeException('Unknown deletion policy:' . $command->policy);
        }
        $table = $this->wrapTable($blueprint);
        $column = $this->wrap($command->column);
        return "alter table {$table} add row deletion policy (older_than({$column}, interval {$command->days} day))";
    }

    /**
     * @param Blueprint $blueprint
     * @param RowDeletionPolicyDefinition $command
     * @return string
     */
    public function compileReplaceRowDeletionPolicy(Blueprint $blueprint, Fluent $command)
    {
        if ($command->policy !== 'olderThan') {
            throw new RuntimeException('Unknown deletion policy:' . $command->policy);
        }
        $table = $this->wrapTable($blueprint);
        $column = $this->wrap($command->column);
        return "alter table {$table} replace row deletion policy (older_than({$column}, interval {$command->days} day))";
    }

    /**
     * @param Blueprint $blueprint
     * @param RowDeletionPolicyDefinition $command
     * @return string
     */
    public function compileDropRowDeletionPolicy(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table ' . $this->wrapTable($blueprint) . ' drop row deletion policy';
    }

    /**
     * @param Blueprint $blueprint
     * @param SequenceDefinition $command
     * @return string
     */
    public function compileCreateSequence(Blueprint $blueprint, Fluent $command): string
    {
        return "create sequence {$this->wrap($command->sequence)} {$this->formatSequenceOptions($command)}";
    }

    /**
     * @param Blueprint $blueprint
     * @param SequenceDefinition $command
     * @return string
     */
    public function compileCreateSequenceIfNotExists(Blueprint $blueprint, Fluent $command): string
    {
        return "create sequence if not exists {$this->wrap($command->sequence)} {$this->formatSequenceOptions($command)}";
    }

    /**
     * @param Blueprint $blueprint
     * @param SequenceDefinition $command
     * @return string
     */
    public function compileAlterSequence(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter sequence ' . $this->wrap($command->sequence) . ' set ' . $this->formatSequenceOptions($command);
    }

    /**
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed>&object{ sequence: string } $command
     * @return string
     */
    public function compileDropSequence(Blueprint $blueprint, object $command): string
    {
        return 'drop sequence ' . $this->wrap($command->sequence);
    }

    /**
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed>&object{ sequence: string } $command
     * @return string
     */
    public function compileDropSequenceIfExists(Blueprint $blueprint, object $command): string
    {
        return 'drop sequence if exists ' . $this->wrap($command->sequence);
    }

    /**
     * @param SequenceDefinition $definition
     * @return string
     */
    protected function formatSequenceOptions(mixed $definition): string
    {
        return 'options (' . $this->formatOptions($definition->getOptions()) . ')';
    }

    /**
     * @param Blueprint $blueprint
     * @param ChangeStreamDefinition $command
     * @return string
     */
    public function compileCreateChangeStream(Blueprint $blueprint, ChangeStreamDefinition $command): string
    {
        return implode(' ', array_filter([
            "create change stream {$this->wrap($command->stream)}",
            $this->formatChangeStreamTables($command),
            $this->formatChangeStreamOptions($command),
        ]));
    }

    /**
     * @param Blueprint $blueprint
     * @param ChangeStreamDefinition $command
     * @return string
     */
    public function compileAlterChangeStream(Blueprint $blueprint, ChangeStreamDefinition $command): string
    {
        $parts = [];
        $parts[] = "alter change stream {$this->wrap($command->stream)}";
        if ($command->getOptions() !== []) {
            $parts[] = 'set ' . $this->formatChangeStreamOptions($command);
        }
        return implode(' ', $parts);
    }

    /**
     * @param Blueprint $blueprint
     * @param ChangeStreamDefinition $command
     * @return string
     */
    public function compileDropChangeStream(Blueprint $blueprint, ChangeStreamDefinition $command): string
    {
        return 'drop change stream ' . $this->wrap($command->stream);
    }

    protected function formatChangeStreamTables(ChangeStreamDefinition $definition): string
    {
        $parts = [];
        foreach ($definition->tables as $table => $columns) {
            $string = $this->wrap($table);
            if ($columnsAsString = $this->columnize($columns)) {
                $string .= "({$columnsAsString})";
            }
            $parts[] = $string;
        }
        return $parts !== []
            ? 'for ' . implode(', ', $parts)
            : 'for all';
    }

    /**
     * @param ChangeStreamDefinition $definition
     * @return string
     */
    protected function formatChangeStreamOptions(ChangeStreamDefinition $definition): string
    {
        $options = $definition->getOptions();

        return $options !== []
            ? 'options (' . $this->formatOptions($options) . ')'
            : '';
    }

    /**
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=ja#create_table
     * @param Blueprint $blueprint
     * @return string
     */
    protected function addInterleaveToTable(Blueprint $blueprint)
    {
        if (!is_null($command = $this->getCommandByName($blueprint, 'interleaveInParent'))) {
            assert($command instanceof InterleaveDefinition);
            $schema = ", interleave in parent {$this->wrap($command->table)}";
            if (!is_null($command->onDelete)) {
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
        if (!is_null($command = $this->getCommandByName($blueprint, 'rowDeletionPolicy'))) {
            /** @var RowDeletionPolicyDefinition $command */
            if ($command->policy === 'olderThan') {
                return ', row deletion policy (older_than(' . $command->column . ', interval ' . $command->days . ' day))';
            }
            throw new RuntimeException('Unknown deletion policy:' . $command->policy);
        }
        return '';
    }

    /**
     * Compile a unique key command.
     *
     * @param Blueprint $blueprint
     * @param IndexDefinition $command
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
     * @param Blueprint $blueprint
     * @param IndexDefinition $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#create_index
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        // if index is defined as assoc array, key is treated as column name and value as order
        // if index is defined as numeric array, then values are read as column names
        $columns = [];
        foreach ($command->columns as $column => $order) {
            $columns[] = is_string($column)
                ? $this->wrap($column) . ' ' . $order
                : $this->wrap($order);
        }

        return sprintf('create %s%sindex %s on %s (%s)%s%s',
            empty($command->indexType) ? '' : trim($command->indexType) . ' ',
            empty($command->nullFiltered) ? '' : 'null_filtered ',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            implode(', ', $columns),
            $this->addStoringToIndex($command),
            $this->addInterleaveToIndex($command),
        );
    }

    /**
     * @param IndexDefinition $indexCommand
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#create_index
     */
    protected function addInterleaveToIndex(Fluent $indexCommand): string
    {
        return empty($indexCommand->interleaveIn) ? '' : ", interleave in {$this->wrap($indexCommand->interleaveIn)}";
    }

    /**
     * @param Fluent<string, mixed> $indexCommand
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
        return ' storing (' . implode(', ', $storings) . ')';
    }

    /**
     * @param Blueprint $blueprint
     * @param IndexDefinition $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        return sprintf('drop index %s',
            $this->wrap($command->index),
        );
    }

    /**
     * @param Blueprint $blueprint
     * @param IndexDefinition $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Compile a drop foreign key command.
     *
     * @param Blueprint $blueprint
     * @param IndexDefinition $command
     * @return string
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        $index = $this->wrap($command->index);

        return "alter table {$this->wrapTable($blueprint)} drop constraint {$index}";
    }

    /**
     * Get the primary key syntax for a table creation statement.
     *
     * @param Blueprint $blueprint
     * @return string
     */
    protected function addPrimaryKeys(Blueprint $blueprint)
    {
        if (!is_null($primary = $this->getCommandByName($blueprint, 'primary'))) {
            /** @var IndexDefinition $primary */
            return "primary key ({$this->columnize($primary->columns)})";
        }
        return '';
    }

    /**
     * Compile a drop table command.
     * Note: you can't drop a table if there are indexes over it, or if there are any tables or indexes interleaved within it.
     *
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed> $command
     * @return string
     * @see https://cloud.google.com/spanner/docs/data-definition-language?hl=en#drop_table
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @param Blueprint $blueprint
     * @param Fluent<string, mixed> $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table if exists ' . $this->wrapTable($blueprint);
    }

    /**
     * Create the column definition for a string type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeString(Fluent $column)
    {
        return "string({$column->length})";
    }

    /**
     * Create the column definition for a char type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeChar(Fluent $column)
    {
        return $this->typeString($column);
    }

    /**
     * Create the column definition for a text type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeText(Fluent $column)
    {
        return "string(max)";
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeMediumText(Fluent $column)
    {
        return $this->typeText($column);
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeLongText(Fluent $column)
    {
        return $this->typeText($column);
    }

    /**
     * Create the column definition for a json type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeJson(Fluent $column)
    {
        return 'json';
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeBinary(Fluent $column)
    {
        return "bytes({$column->length})";
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column)
    {
        return 'int64';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeInteger(Fluent $column)
    {
        return $this->typeBigInteger($column);
    }

    /**
     * Create the column definition for a float type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeFloat(Fluent $column)
    {
        return $this->typeDouble($column);
    }

    /**
     * Create the column definition for a double type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeDouble(Fluent $column)
    {
        return 'float64';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeDecimal(Fluent $column)
    {
        return "numeric";
    }

    /**
     * Create the column definition for a date type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeDate(Fluent $column)
    {
        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeDateTime(Fluent $column)
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column)
    {
        if ($column->useCurrent) {
            $column->default(new Expression('current_timestamp()'));
        }

        return 'timestamp';
    }

    /**
     * Create the column definition for an uuid type.
     *
     * @param ColumnDefinition $column
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
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeArray(Fluent $column)
    {
        return 'array<' . $this->getArrayInnerType($column) . '>';
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param ColumnDefinition $column
     * @return string
     */
    protected function typeBoolean(Fluent $column)
    {
        return 'bool';
    }

    /**
     * @param Fluent<string, mixed>&object{ function: TokenizerFunction, target: string, options: array<string, scalar> } $column
     * @return string
     */
    protected function typeTokenList(Fluent $column): string
    {
        return 'tokenlist as (' . $column->function->value . '(' . implode(', ', array_filter([
            $this->wrap($column->target),
            $this->formatOptions($column->options, ' => '),
        ])) . '))';
    }

    /**
     * Get the SQL for an invisible column modifier.
     *
     * @param Blueprint $blueprint
     * @param ColumnDefinition&object{ invisible: bool } $column
     * @return string|null
     */
    protected function modifyInvisible(Blueprint $blueprint, Fluent $column)
    {
        return $column->invisible !== null
            ? ' hidden'
            : null;
    }

    /**
     * Get the SQL for an identity column modifier.
     *
     * @param Blueprint $blueprint
     * @param  ColumnDefinition  $column
     * @return string|null
     */
    protected function modifyGeneratedAs(Blueprint $blueprint, Fluent $column): ?string
    {
        $as = $column->generatedAs;

        if ($as === null) {
            return null;
        }

        $expression = match (true) {
            $as === true => 'bit_reversed_positive',
            $as instanceof ExpressionContract => $this->getValue($as),
            default => $as,
        };

        $startingValue = $column->startingValue;

        if (is_int($startingValue)) {
            $expression .= " start counter with {$startingValue}";
        }

        return " generated by default as identity ({$expression})";
    }

    /**
     * @param Blueprint $blueprint
     * @param IntColumnDefinition $column
     * @return string|null
     */
    protected function modifyUseSequence(Blueprint $blueprint, Fluent $column): ?string
    {
        if (isset($column->useSequence)) {
            return " default (get_next_sequence_value(sequence {$this->wrap($column->useSequence)}))";
        }
        return null;
    }

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @param Blueprint $blueprint
     * @param ColumnDefinition $column
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
     * @param ColumnDefinition $column
     * @return string
     */
    protected function getArrayInnerType(Fluent $column): string
    {
        assert($column->arrayType !== null);
        return $this->{'type' . ucfirst($column->arrayType)}($column);
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param Blueprint $blueprint
     * @param ColumnDefinition $column
     * @return string|null
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        $value = $column->default;

        if (is_null($value)) {
            return null;
        }

        return ' default (' . $this->formatDefaultValue($column, $this->getType($column), $value) . ')';
    }

    /**
     * @param ColumnDefinition $column
     * @param string $type
     * @param mixed $value
     * @return int|float|string
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
            'numeric' => $this->formatNumericValue($column, $value),
            'int64' => $this->formatIntValue($column, $value),
            'string' => $this->formatStringValue($column, $value),
            'timestamp' => $this->formatTimestampValue($column, $value),
            default => throw new LogicException('Unsupported default for ' . $type . ' column: ' . $column->toJson()),
        };
    }

    /**
     * @param ColumnDefinition $column
     * @param mixed $value
     * @return string
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
     * @param ColumnDefinition $column
     * @param mixed $value
     * @return string
     */
    protected function formatBoolValue(Fluent $column, mixed $value): string
    {
        assert(is_bool($value));
        return $value ? 'true' : 'false';
    }

    /**
     * @param ColumnDefinition $column
     * @param mixed $value
     * @return string
     */
    protected function formatDateValue(Fluent $column, mixed $value): string
    {
        assert(is_string($value) || $value instanceof DateTimeInterface);
        if (is_string($value)) {
            $value = Carbon::parse($value);
        }
        return 'date "' . $value->format('Y-m-d') . '"';
    }

    /**
     * @param ColumnDefinition $column
     * @param mixed $value
     * @return string
     */
    protected function formatFloatValue(Fluent $column, mixed $value): string
    {
        assert(is_float($value));
        return (string)$value;
    }

    /**
     * @param ColumnDefinition $column
     * @param mixed $value
     * @return string
     */
    protected function formatNumericValue(Fluent $column, mixed $value): string
    {
        assert(is_numeric($value));
        return (string)$value;
    }

    /**
     * @param ColumnDefinition $column
     * @param mixed $value
     * @return string
     */
    protected function formatIntValue(Fluent $column, mixed $value): string
    {
        assert(is_int($value));
        return (string)$value;
    }

    /**
     * @param Fluent<string, mixed> $column
     * @param mixed $value
     * @return string
     */
    protected function formatStringValue(Fluent $column, mixed $value): string
    {
        assert(is_string($value));
        return '"' . $value . '"';
    }

    /**
     * @param Fluent<string, mixed> $column
     * @param mixed $value
     * @return string
     */
    protected function formatTimestampValue(Fluent $column, mixed $value): string
    {
        assert(is_string($value) || $value instanceof DateTimeInterface);
        if (is_string($value)) {
            $value = Carbon::parse($value);
        }
        return 'timestamp "' . $value->format($this->getDateFormat()) . '"';
    }

    /**
     * Compile the blueprint's column definitions.
     *
     * @deprecated Not used anymore. Will be deleted in 9.x.
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
            $sql = $this->wrap($column) . ' ' . $this->getType($column);

            $columns[] = $this->addModifiers($sql, $blueprint, $column);
        }

        return $columns;
    }
}
