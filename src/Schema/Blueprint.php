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

use Colopl\Spanner\Concerns\MarksAsNotSupported;
use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;

/**
 * @method IndexDefinition index(string|string[] $columns, string|null $name = null)
 * @method IndexDefinition unique(string|string[] $columns, string|null $name = null)
 */
class Blueprint extends BaseBlueprint
{
    use MarksAsNotSupported;

    /**
     * @inheritDoc
     * @return IntColumnDefinition
     */
    public function bigInteger($column, $autoIncrement = false, $unsigned = false): IntColumnDefinition
    {
        $definition = new IntColumnDefinition($this, ['type' => __FUNCTION__, 'name' => $column, 'autoIncrement' => $autoIncrement]);
        $this->addColumnDefinition($definition);
        return $definition;
    }

    /**
     * @inheritDoc
     * @return IntColumnDefinition
     */
    public function integer($column, $autoIncrement = false, $unsigned = false): IntColumnDefinition
    {
        return $this->bigInteger($column, $autoIncrement, $unsigned);
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function temporary()
    {
        $this->markAsNotSupported('temporary table');
    }

    /**
     * {@inheritDoc}
     * @param string|array<array-key, mixed>|null $index
     * @return never
     */
    public function dropPrimary($index = null)
    {
        $this->markAsNotSupported('dropping primary key');
    }

    /**
     * @inheritDoc
     */
    public function increments($column)
    {
        return $this->uuid($column)
            ->generateUuid()
            ->primary();
    }

    /**
     * @inheritDoc
     */
    public function bigIncrements($column)
    {
        return $this->increments($column);
    }

    /**
     * @inheritDoc
     */
    public function mediumIncrements($column)
    {
        return $this->increments($column);
    }

    /**
     * @inheritDoc
     */
    public function smallIncrements($column)
    {
        return $this->increments($column);
    }

    /**
     * @inheritDoc
     */
    public function tinyIncrements($column)
    {
        return $this->increments($column);
    }

    /**
     * @inheritDoc
     * @param int|'max'|null $length add support for 'max'
     */
    public function string($column, $length = null)
    {
        /** @phpstan-ignore argument.type */
        return parent::string($column, $length);
    }

    /**
     * @inheritDoc
     * @return UuidColumnDefinition
     */
    public function uuid($column = 'uuid')
    {
        $definition = new UuidColumnDefinition(['type' => 'uuid', 'name' => $column]);
        $this->addColumnDefinition($definition);
        return $definition;
    }

    /**
     * @param string $column
     * @param int|null $length
     * @param bool $fixed
     * @return ColumnDefinition
     */
    public function binary($column, $length = null, $fixed = false)
    {
        $length = $length ?: Builder::$defaultBinaryLength;

        return $this->addColumn('binary', $column, compact('length'));
    }

    /**
     * @param string $column
     * @param int $total
     * @param int $places
     * @return ColumnDefinition
     */
    public function decimal($column, $total = 38, $places = 9)
    {
        if ($total !== 38) {
            $this->markAsNotSupported('decimal with precision other than 38');
        }

        if ($places !== 9) {
            $this->markAsNotSupported('decimal with scale other than 9');
        }

        return parent::decimal($column, $total, $places);
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function booleanArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'boolean',
        ]);
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function integerArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'integer',
        ]);
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function floatArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'float',
        ]);
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function decimalArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'decimal',
        ]);
    }

    /**
     * @param string $column
     * @param int|string|null $length
     * @return ColumnDefinition
     */
    public function stringArray($column, $length = null)
    {
        $length ??= Builder::$defaultStringLength;

        return $this->addColumn('array', $column, [
            'arrayType' => 'string',
            'length' => $length,
        ]);
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function dateArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'date',
        ]);
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function timestampArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'timestamp',
        ]);
    }

    /**
     * @param string $column
     * @param TokenizerFunction $function
     * @param string $target
     * @param array<array-key, mixed> $options
     * @return ColumnDefinition
     */
    public function tokenList(string $column, TokenizerFunction $function, string $target, array $options = []): ColumnDefinition
    {
        return $this->addColumn('tokenList', $column, [
            'function' => $function,
            'target' => $target,
            'options' => $options,
        ])->invisible()->nullable();
    }

    /**
     * @param string $table
     * @return InterleaveDefinition
     */
    public function interleaveInParent(string $table): InterleaveDefinition
    {
        return $this->interleaveIn($table, true);
    }

    /**
     * @param string $table
     * @param bool $parent
     * @return InterleaveDefinition
     */
    public function interleaveIn(string $table, bool $parent = false): InterleaveDefinition
    {
        return $this->commands[] = new InterleaveDefinition(
            $this->createCommand('interleaveIn', [
                'table' => $table,
                'inParent' => $parent,
            ])->getAttributes(),
        );
    }

    /**
     * @param string|list<string> $columns
     * @param string|null $name
     * @param string|null $algorithm
     * @return SearchIndexDefinition
     */
    public function fullText($columns, $name = null, $algorithm = null)
    {
        $type = 'fullText';
        $columns = (array) $columns;

        $this->commands[] = $command = new SearchIndexDefinition([
            'name' => $type,
            'index' => $name ?? $this->createIndexName($type, $columns),
            'columns' => $columns,
            'algorithm' => $algorithm,
        ]);

        return $command;
    }

    /**
     * {@inheritdoc}
     * @return RenameDefinition
     */
    public function rename($to): RenameDefinition
    {
        $this->commands[] = $command = new RenameDefinition(__FUNCTION__, $to);
        return $command;
    }

    /**
     * @param string $name
     * @return RenameDefinition
     */
    public function addSynonym(string $name): RenameDefinition
    {
        $this->commands[] = $command = (new RenameDefinition(__FUNCTION__, ''))->synonym($name);
        return $command;
    }

    /**
     * @param string $name
     * @return RenameDefinition
     */
    public function dropSynonym(string $name): RenameDefinition
    {
        $this->commands[] = $command = (new RenameDefinition(__FUNCTION__, ''))->synonym($name);
        return $command;
    }

    /**
     * @see https://cloud.google.com/spanner/docs/ttl#defining_a_row_deletion_policy
     * @param string $column
     * @param int $days
     * @return Fluent<string, mixed>
     */
    public function deleteRowsOlderThan(string $column, int $days)
    {
        return $this->addCommand('rowDeletionPolicy', [
            'policy' => 'olderThan',
            'column' => $column,
            'days' => $days,
        ]);
    }

    /**
     * @param string $column
     * @param int $days
     * @return Fluent<string, mixed>
     */
    public function addRowDeletionPolicy(string $column, int $days): Fluent
    {
        return $this->addCommand('addRowDeletionPolicy', [
            'policy' => 'olderThan',
            'column' => $column,
            'days' => $days,
        ]);
    }

    /**
     * @param string $column
     * @param int $days
     * @return Fluent<string, mixed>
     */
    public function replaceRowDeletionPolicy(string $column, int $days): Fluent
    {
        return $this->addCommand('replaceRowDeletionPolicy', [
            'policy' => 'olderThan',
            'column' => $column,
            'days' => $days,
        ]);
    }

    /**
     * @return Fluent<string, mixed>
     */
    public function dropRowDeletionPolicy(): Fluent
    {
        return $this->addCommand('dropRowDeletionPolicy');
    }

    /**
     * @param string $name
     * @return SequenceDefinition
     */
    public function createSequence(string $name): SequenceDefinition
    {
        $this->commands[] = $command = new SequenceDefinition(__FUNCTION__, $name);
        return $command;
    }

    /**
     * @param string $name
     * @return SequenceDefinition
     */
    public function createSequenceIfNotExists(string $name): SequenceDefinition
    {
        $this->commands[] = $command = new SequenceDefinition(__FUNCTION__, $name);
        return $command;
    }

    /**
     * @param string $name
     * @return SequenceDefinition
     */
    public function alterSequence(string $name): SequenceDefinition
    {
        $this->commands[] = $command = new SequenceDefinition(__FUNCTION__, $name);
        return $command;
    }

    /**
     * @param string $name
     * @return Fluent<string, mixed>
     */
    public function dropSequence(string $name): Fluent
    {
        return $this->addCommand(__FUNCTION__, ['sequence' => $name]);
    }

    /**
     * @param string $name
     * @return Fluent<string, mixed>
     */
    public function dropSequenceIfExists(string $name): Fluent
    {
        return $this->addCommand(__FUNCTION__, ['sequence' => $name]);
    }

    /**
     * @param string $name
     * @return ChangeStreamDefinition
     */
    public function createChangeStream(string $name): ChangeStreamDefinition
    {
        $this->commands[] = $command = new ChangeStreamDefinition(__FUNCTION__, $name);
        return $command;
    }

    /**
     * @param string $name
     * @return ChangeStreamDefinition
     */
    public function alterChangeStream(string $name): ChangeStreamDefinition
    {
        $this->commands[] = $command = new ChangeStreamDefinition(__FUNCTION__, $name);
        return $command;
    }

    public function dropChangeStream(string $name): ChangeStreamDefinition
    {
        $this->commands[] = $command = new ChangeStreamDefinition(__FUNCTION__, $name);
        return $command;
    }

    /**
     * @inheritDoc
     * @param string|list<string> $columns
     * @return IndexDefinition
     */
    protected function indexCommand($type, $columns, $index, $algorithm = null, $operatorClass = null)
    {
        $columns = (array) $columns;

        // If no name was specified for this index, we will create one using a basic
        // convention of the table name, followed by the columns, followed by an
        // index type, such as primary or index, which makes the index unique.
        $index = $index ?: $this->createIndexName($type, $columns);

        $this->commands[] = $command = new IndexDefinition([
            'name' => $type,
            'index' => $index,
            'columns' => $columns,
            'algorithm' => $algorithm,
            'operatorClass' => $operatorClass,
        ]);

        return $command;
    }

    /**
     * @inheritDoc
     * @param list<string> $columns
     */
    protected function createIndexName($type, array $columns)
    {
        [$schema, $table] = $this->connection
            ->getSchemaBuilder()
            ->parseSchemaAndTable($this->table);

        if ($this->connection->getConfig('prefix_indexes')) {
            $table = $this->connection->getTablePrefix() . $table;
        }

        $index = strtolower($table . '_' . implode('_', $columns) . '_' . $type);

        if ($type !== 'foreign' && $schema !== null) {
            $index = $schema . '.' . $index;
        }

        return str_replace('-', '_', $index);
    }

}
