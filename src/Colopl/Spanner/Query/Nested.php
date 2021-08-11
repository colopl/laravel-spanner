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

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;

/**
 * @internal use only for UNNESTing
 */
class Nested implements Arrayable, IteratorAggregate, Countable
{
    /**
     * @var array
     */
    private $array;

    /**
     * @param array|Arrayable $array
     */
    public function __construct($array)
    {
        $this->array = ($array instanceof Arrayable)
            ? $array->toArray()
            : $array;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return array_values($this->array);
    }

    /**
     * @return ArrayIterator
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->array);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->array);
    }
}
