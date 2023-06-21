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

use Colopl\Spanner\Connection;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;

class Builder extends BaseBuilder
{
    use Concerns\UsesMutations,
        Concerns\UsesPartitionedDml,
        Concerns\UsesStaleReads;

    /**
     * @var Connection
     */
    public $connection;

    /**
     * @inheritDoc
     */
    public function insert(array $values)
    {
        return parent::insert($this->prepareInsertForDml($values));
    }

    /**
     * @inheritDoc
     */
    public function updateOrInsert(array $attributes, array $values = [])
    {
        if (! $this->where($attributes)->exists()) {
            return $this->insert(array_merge($attributes, $values));
        }

        return (bool) $this->take(1)->update(Arr::except($values, array_keys($attributes)));
    }

    /**
     * @inheritDoc
     */
    public function truncate()
    {
        $this->applyBeforeQueryCallbacks();

        foreach ($this->grammar->compileTruncate($this) as $sql => $bindings) {
            $this->connection->runPartitionedDml($sql, $bindings);
        }
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
     * @param array<array-key, mixed>|Arrayable<array-key, mixed>|Nested $values
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
     * @param array<array-key, mixed> $values
     * @return array<int, mixed>
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
     * @inheritDoc
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
