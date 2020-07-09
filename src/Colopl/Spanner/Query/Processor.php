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

use Exception;
use Google\Cloud\Spanner\Timestamp;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

class Processor extends \Illuminate\Database\Query\Processors\Processor
{
    /**
     * @param Builder $query
     * @param array<mixed> $results
     * @return array<array<mixed>>
     * @throws Exception
     */
    public function processSelect(Builder $query, $results): array
    {
        foreach ($results as $index => $result) {
            foreach ($result as $k => $v) {
                // Convert TIMESTAMP column values to Carbon instances
                if ($v instanceof Timestamp) {
                    $dt = Carbon::instance($v->get());
                    $dt->setTimezone(date_default_timezone_get());
                    $results[$index][$k] = $dt;
                }
            }
        }
        return $results;
    }

    /**
     * @param  array<mixed>  $results
     * @return array<string>
     */
    public function processColumnListing($results)
    {
        return array_map(function ($result) {
            return ((object) $result)->column_name;
        }, $results);
    }

    /**
     * @param  array<mixed>  $results
     * @return array<string>
     */
    public function processIndexListing($results)
    {
        return array_map(function ($result) {
            return ((object) $result)->index_name;
        }, $results);
    }
}
