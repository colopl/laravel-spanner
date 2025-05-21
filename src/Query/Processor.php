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

use Google\Cloud\Spanner\Numeric;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\ValueInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor as BaseProcessor;
use Illuminate\Support\Carbon;
use LogicException;

class Processor extends BaseProcessor
{
    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $values
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $connection = $query->getConnection();

        $connection->recordsHaveBeenModified();

        $queryCall = static fn() => $connection->selectOne($sql, $values);

        $result = $connection->transactionLevel() > 0
            ? $queryCall()
            : $connection->transaction($queryCall);

        $sequence ??= 'id';

        $id = match(true) {
            is_object($result) => $result->{$sequence},
            is_array($result) => $result[$sequence],
            default => throw new LogicException('Unknown result type : ' . gettype($result)),
        };

        assert(is_int($id));

        return $id;
    }

    /**
     * @inheritDoc
     * @param array<array-key, array<array-key, mixed>> $results
     * @return array<array-key, array<array-key, mixed>>
     */
    public function processSelect(Builder $query, $results): array
    {
        foreach ($results as $index => $result) {
            foreach ($result as $name => $value) {
                if ($value instanceof ValueInterface) {
                    $results[$index][$name] = $this->processColumn($value);
                } elseif (is_array($value)) {
                    $array = [];
                    foreach ($value as $k => $v) {
                        $array[$k] = ($v instanceof ValueInterface)
                            ? $this->processColumn($v)
                            : $v;
                    }
                    $results[$index][$name] = $array;
                }
            }
        }
        return $results;
    }

    /**
     * @template TValue of mixed
     * @param TValue $value
     * @return ($value is Timestamp ? Carbon : ($value is Numeric ? string : TValue))
     */
    protected function processColumn(mixed $value): mixed
    {
        if ($value instanceof Timestamp) {
            return Carbon::instance($value->get())->setTimezone(date_default_timezone_get());
        }

        if ($value instanceof Numeric) {
            return $value->formatAsString();
        }

        return $value;
    }

    /**
     * @inheritDoc
     */
    public function processTables($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'schema' => $result->schema !== '' ? $result->schema : null,
                'schema_qualified_name' => $result->schema !== ''
                    ? $result->schema . '.' . $result->name
                    : $result->name,
                'parent' => $result->parent,
                'size' => null,
                'comment' => null,
                'collation' => null,
                'engine' => null,
            ];
        }, $results);
    }

    /**
     * @inheritDoc
     */
    public function processColumns($results)
    {
        return array_map(static function (array $result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'type_name' => (string) preg_replace("/\([^)]+\)/", "", $result->type),
                'type' => $result->type,
                'collation' => null,
                'nullable' => $result->nullable === 'YES',
                'default' => $result->default,
                // TODO check IS_IDENTITY and set auto_increment accordingly
                'auto_increment' => false,
                'comment' => null,
                'generation' => null,
            ];
        }, $results);
    }

    /**
     * @inheritDoc
     */
    public function processIndexes($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $name = $result->name,
                'columns' => $result->columns ? explode(',', $result->columns) : [],
                'type' => strtolower($result->type),
                'unique' => (bool) $result->unique,
                'primary' => $name === 'PRIMARY_KEY',
            ];
        }, $results);
    }

    /**
     * @inheritDoc
     */
    public function processForeignKeys($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'columns' => explode(',', $result->columns),
                'foreign_schema' => $result->foreign_schema,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => explode(',', $result->foreign_columns),
                'on_update' => strtolower($result->on_update),
                'on_delete' => strtolower($result->on_delete),
            ];
        }, $results);
    }
}
