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

declare(strict_types=1);

namespace Colopl\Spanner\Query;

use Google\Cloud\Spanner\Uuid;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Marks a list of values as UUIDs so they are sent to Spanner as `ARRAY<UUID>`
 * instead of `ARRAY<STRING>`.
 *
 * A single string binding is coerced to `UUID` by Spanner, so `where('id', $uuid)`
 * needs no help. An array binding is **not** coerced, so a query which sends one
 * against a column of the native `UUID` type fails with
 * `No matching signature for operator IN UNNEST for argument types: UUID, ARRAY<STRING>`.
 *
 * {@see Builder::whereIn()} switches to `UNNEST()` once the number of values
 * exceeds `parameter_unnest_threshold`, so an unmarked query can work for a small
 * list and fail for a large one. Marking the values makes it work for both.
 *
 * ```php
 * $query->whereIn('id', Uuids::from($ids));
 * ```
 */
final class Uuids
{
    /**
     * @param iterable<array-key, Uuid|string>|Arrayable<array-key, Uuid|string> $values
     * @return list<Uuid>
     */
    public static function from(iterable|Arrayable $values): array
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $uuids = [];
        foreach ($values as $value) {
            if ($value instanceof Uuid) {
                $uuids[] = $value;
                continue;
            }
            assert(is_string($value));
            $uuids[] = new Uuid($value);
        }
        return $uuids;
    }
}
