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
     * @return never
     */
    public function temporary()
    {
        $this->markAsNotSupported('temporary table');
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function dropPrimary($index = null)
    {
        $this->markAsNotSupported('dropping primary key');
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function increments($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function bigIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function mediumIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function smallIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function tinyIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    // region Spanner Specific Types

    /**
     * @param  string  $column
     * @param int|null $length
     * @return ColumnDefinition
     */
    public function binary($column, $length = null)
    {
        $length = $length ?: Builder::$defaultBinaryLength;

        return $this->addColumn('binary', $column, compact('length'));
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function booleanArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'boolean'
        ]);
    }

    /**
     * @param string $column
     * @return ColumnDefinition
     */
    public function integerArray($column)
    {
        return $this->addColumn('array', $column, [
            'arrayType' => 'integer'
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
     * @deprecated use interleaveInParent instead.
     * @param string $parentTableName
     * @return InterleaveDefinition
     */
    public function interleave(string $parentTableName)
    {
        trigger_deprecation('colopl/laravel-spanner', '5.2', 'Blueprint::interleave() is deprecated. Use interleaveInParent() instead. This method will be removed in v7.');
        return $this->interleaveInParent($parentTableName);
    }

    /**
     * @param string $table
     * @return InterleaveDefinition
     */
    public function interleaveInParent(string $table): InterleaveDefinition
    {
        $command = new InterleaveDefinition(
            $this->addCommand('interleaveInParent', compact('table'))->getAttributes()
        );

        $this->commands[count($this->commands) - 1] = $command;

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
     * @param string $type
     * @param string|list<string> $columns
     * @param string $index
     * @param string|null $algorithm
     * @return IndexDefinition
     */
    protected function indexCommand($type, $columns, $index, $algorithm = null)
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
        ]);

        return $command;
    }
}
