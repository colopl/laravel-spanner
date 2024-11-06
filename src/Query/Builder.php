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

use Closure;
use Colopl\Spanner\Connection;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use LogicException;

class Builder extends BaseBuilder
{
    use Concerns\SetsRequestTimeouts;
    use Concerns\UsesDataBoost;
    use Concerns\UsesMutations;
    use Concerns\UsesPartitionedDml;
    use Concerns\UsesStaleReads;

    public const PARAMETER_LIMIT = 950;
    public const DEFAULT_UNNEST_THRESHOLD = 900;

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
    public function update(array $values)
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = new ArrayValue($value);
            }
        }

        return parent::update($values);
    }

    /**
     * @inheritDoc
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        $exists = $this->where($attributes)->exists();

        if ($values instanceof Closure) {
            $values = $values($exists);
        }

        if (!$exists) {
            return $this->insert(array_merge($attributes, $values));
        }

        if (empty($values)) {
            return true;
        }

        return (bool)$this->limit(1)->update(Arr::except($values, array_keys($attributes)));
    }

    /**
     * @inheritDoc
     */
    public function upsert(array $values, $uniqueBy = [], $update = null)
    {
        if (empty($values)) {
            return 0;
        }

        if (!array_is_list($values)) {
            $values = [$values];
        } else {
            foreach ($values as $key => $value) {
                ksort($value);

                $values[$key] = $value;
            }
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->affectingStatement(
            $this->grammar->compileUpsert($this, $values, [], []),
            $this->cleanBindings(Arr::flatten($values, 1)),
        );
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
     * @inheritDoc
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        // If parameter is over the limit, Spanner will throw an error. We will bypass this limit by
        // using UNNEST(). This is enabled by default, but can be disabled by setting the config.
        $unnestThreshold = $this->connection->getConfig('parameter_unnest_threshold') ?? self::DEFAULT_UNNEST_THRESHOLD;
        if ($unnestThreshold !== false && is_countable($values) && count($values) > $unnestThreshold) {
            return $this->whereInUnnest($column, $values, $boolean, $not);
        }

        return parent::whereIn($column, $values, $boolean, $not);
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
     * @param string|Expression $column
     * @param mixed $values
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereInUnnest(string|Expression $column, $values, string $boolean = 'and', bool $not = false)
    {
        $type = 'InUnnest';

        // prevent getBindings() from flattening the array by wrapping it in a class
        $values = ($values instanceof Nested) ? $values : new Nested($values);

        $this->wheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        $this->addBinding($values);

        return $this;
    }

    /**
     * @param string $column
     * @param array<array-key, mixed>|Arrayable<array-key, mixed>|Nested $values
     * @param string $boolean
     * @return $this
     */
    public function whereNotInUnnest(string $column, array|Arrayable|Nested $values, string $boolean = 'and'): static
    {
        return $this->whereInUnnest($column, $values, $boolean, true);
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
        $sql = $this->toSql();
        $bindings = $this->getBindings();
        $options = [];

        $requestTimeoutSeconds = $this->getRequestTimeoutSeconds();
        if ($requestTimeoutSeconds !== null) {
            $options['requestTimeout'] = $requestTimeoutSeconds;
        }

        if ($this->dataBoostEnabled()) {
            $options['dataBoostEnabled'] = true;
        }

        if ($this->timestampBound !== null) {
            $options += $this->timestampBound->transactionOptions();
        }

        return $this->connection->selectWithOptions($sql, $bindings, $options);
    }

    /**
     * @param string $index
     * @return $this
     */
    public function forceIndex($index): static
    {
        $this->indexHint = new IndexHint('force', $index);

        return $this;
    }

    /**
     * @return $this
     */
    public function disableEmulatorNullFilteredIndexCheck(): static
    {
        $indexHint = $this->indexHint;

        if ($indexHint === null) {
            throw new LogicException('Force index must be set before disabling null filter index check');
        }

        assert($indexHint instanceof IndexHint);
        $indexHint->disableEmulatorNullFilteredIndexCheck = true;

        return $this;
    }
}
