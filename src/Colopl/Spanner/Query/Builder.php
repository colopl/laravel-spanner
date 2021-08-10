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

namespace Colopl\Spanner\Query;

use BadMethodCallException;
use Colopl\Spanner\Concerns\MarksAsNotSupported;
use Colopl\Spanner\Connection;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Throwable;

class Builder extends BaseBuilder
{
    use Concerns\AppliesForceIndex,
        Concerns\UsesMutations,
        Concerns\UsesPartitionedDml,
        Concerns\UsesStaleReads,
        MarksAsNotSupported;

    /**
     * @var Connection
     */
    public $connection;

    /**
     * @param array $values
     * @return bool
     */
    public function insert(array $values)
    {
        return parent::insert($this->prepareInsertForDml($values));
    }

    /**
     * This will return the last value used since Spanner does not have the
     * feature to return the last inserted ID.
     *
     * @param  array        $values
     * @param  string|null  $sequence the name of primary key
     * @return string
     * @throws InvalidArgumentException
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $values = $this->prepareInsertForDml($values);
        $this->insert($values);

        $lastValue = array_values(array_slice($values, -1))[0];
        return $lastValue[$sequence] ?? null;
    }

    /**
     * @param array $attributes
     * @param array $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array $values = [])
    {
        if (! $this->where($attributes)->exists()) {
            return $this->insert(array_merge($attributes, $values));
        }

        return (bool) $this->take(1)->update(Arr::except($values, array_keys($attributes)));
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function truncate()
    {
        $this->markAsNotSupported('truncate table');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function sharedLock()
    {
        $this->markAsNotSupported('shared lock');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     */
    public function lockForUpdate()
    {
        $this->markAsNotSupported('lock for update');
    }

    /**
     * NOTE: We will attempt to bind column names included in UNNEST() here.
     * @see https://cloud.google.com/spanner/docs/lexical#query-parameters
     * > Query parameters can be used in substitution of arbitrary expressions.
     * > They cannot, however, be used in substitution of identifiers,
     * > column names, table names, or other parts of the query itself.
     * The regular expression was taken from the documentation below
     * @see https://cloud.google.com/spanner/docs/lexical
     *
     * @param string $column
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function whereInArray(string $column, $value, string $boolean = 'and')
    {
        $type = 'InArray';

        $this->wheres[] = compact('type', 'column', 'value', 'boolean');

        $this->addBinding($value);

        return $this;
    }

    /**
     * @param string $column
     * @param array|Arrayable|Nested $values
     * @param string $boolean
     * @return $this
     */
    public function whereInUnnest(string $column, $values, string $boolean = 'and')
    {
        $type = 'InUnnest';

        // prevent getBindings() from flattening the array by wrapping it in a class
        $values = ($values instanceof Nested) ? $values : new Nested($values);

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        $this->addBinding($values);

        return $this;
    }

    /**
     * @param array $values
     * @return array
     */
    protected function prepareInsertForDml($values)
    {
        if (empty($values)) {
            return [];
        }

        if (Arr::isAssoc($values)) {
            return [$values];
        }

        return $values;
    }

    /**
     * @return array
     * @throws Throwable
     */
    protected function runSelect()
    {
        if ($this->timestampBound !== null) {
            return $this->connection->selectWithTimestampBound(
                $this->toSql(), $this->getBindings(), $this->timestampBound
            );
        }

        return parent::runSelect();
    }
}
