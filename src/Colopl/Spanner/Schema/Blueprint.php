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
use Illuminate\Support\Fluent;

class Blueprint extends \Illuminate\Database\Schema\Blueprint
{
    use MarksAsNotSupported;

    public function temporary()
    {
        $this->markAsNotSupported('temporary table');
    }

    public function dropPrimary($index = null)
    {
        $this->markAsNotSupported('dropping primary key');
    }

    public function increments($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    public function bigIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    public function mediumIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    public function smallIncrements($column)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

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
     * @return \Illuminate\Support\Fluent
     */
    public function binary($column, $length = null)
    {
        $length = $length ?: Builder::$defaultBinaryLength;

        return $this->addColumn('binary', $column, compact('length'));
    }

    /**
     * @param string $column
     * @return Fluent
     */
    public function booleanArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'bool']);
    }

    /**
     * @param string $column
     * @return Fluent
     */
    public function integerArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'int64']);
    }

    /**
     * @param string $column
     * @return Fluent
     */
    public function floatArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'float64']);
    }

    /**
     * @param string $column
     * @param int $length
     * @return Fluent
     */
    public function stringArray($column, $length)
    {
        return $this->addColumn('array', $column, ['arrayType' => "string($length)"]);
    }

    /**
     * @param string $column
     * @return Fluent
     */
    public function dateArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'date']);
    }

    /**
     * @param string $column
     * @return Fluent
     */
    public function timestampArray($column)
    {
        return $this->addColumn('array', $column, ['arrayType' => 'timestamp']);
    }

    /**
     * @param string $parentTableName
     * @return Fluent
     */
    public function interleave(string $parentTableName)
    {
        return $this->addCommand('interleave', compact('parentTableName'));
    }

    // endregion
}
