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

class Processor extends BaseProcessor
{
    /**
     * @inheritDoc
     */
    public function processSelect(Builder $query, $results): array
    {
        foreach ($results as $index => $result) {
            foreach ($result as $name => $value) {
                if ($value instanceof ValueInterface) {
                    $results[$index][$name] = $this->processColumn($value);
                }
                else if (is_array($value)) {
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
    public function processColumnListing($results)
    {
        return array_map(function ($result) {
            return ((object) $result)->column_name;
        }, $results);
    }

    /**
     * Process the results of a columns query.
     *
     * @inheritDoc
     */
    public function processColumns($results)
    {
        return array_map(static function (array $result) {
            return [
                'name' => $result['COLUMN_NAME'],
                'type_name' => preg_replace("/\([^)]+\)/", "", $result['SPANNER_TYPE']),
                'type' => $result['SPANNER_TYPE'],
                'collation' => null,
                'nullable' => $result['IS_NULLABLE'] !== 'NO',
                'default' => $result['COLUMN_DEFAULT'],
                'auto_increment' => false,
                'comment' => null,
            ];
        }, $results);
    }

    /**
     * @deprecated Use processIndexes($results) instead.
     * @param array $results
     * @return array
     */
    public function processIndexListing($results)
    {
        return self::processIndexes($results);
    }

    /**
     * @param array $results
     * @return array
     */
    public function processIndexes($results)
    {
        return array_map(function ($result) {
            return ((object) $result)->index_name;
        }, $results);
    }

    /**
     * @param array $results
     * @return array
     */
    public function processForeignKeys($results)
    {
        return array_map(function ($result) {
            return ((object) $result)->key_name;
        }, $results);
    }
}
