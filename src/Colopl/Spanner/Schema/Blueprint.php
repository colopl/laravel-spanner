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

use BadMethodCallException;
use Colopl\Spanner\Concerns\MarksAsNotSupported;
use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Support\Fluent;

class Blueprint extends BaseBlueprint
{
    use MarksAsNotSupported;

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function temporary()
    {
        $this->markAsNotSupported('temporary table');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function dropPrimary($index = null)
    {
        $this->markAsNotSupported('dropping primary key');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function increments($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function bigIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function mediumIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function smallIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function tinyIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    // region Spanner Specific Types

    /**
     * Create a new binary column on the table.
     *
     * @param  string  $column
     * @param  int  $length
     * @return Fluent<string, mixed>
     */
    public function binary($column, $length = null)
    {
        $length = $length ?: Builder::$defaultBinaryLength;

        return $this->addColumn('binary', $column, compact('length'));
    }

    /**
     * @param string $column
     * @return Fluent<string, mixed>
     */
    public function booleanArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'bool']);
    }

    /**
     * @param string $column
     * @return Fluent<string, mixed>
     */
    public function integerArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'int64']);
    }

    /**
     * @param string $column
     * @return Fluent<string, mixed>
     */
    public function floatArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'float64']);
    }

    /**
     * @param string $column
     * @param int $length
     * @return Fluent<string, mixed>
     */
    public function stringArray($column, $length)
    {
        return $this->addColumn('array', $column, ['arrayType' => "string($length)"]);
    }

    /**
     * @param string $column
     * @return Fluent<string, mixed>
     */
    public function dateArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'date']);
    }

    /**
     * @param string $column
     * @return Fluent<string, mixed>
     */
    public function timestampArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'timestamp']);
    }

    /**
     * @param string $parentTableName
     * @return Fluent<string, mixed>
     */
    public function interleave(string $parentTableName)
    {
        return $this->addCommand('interleave', compact('parentTableName'));
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
}
