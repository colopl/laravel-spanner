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

namespace Colopl\Spanner\Concerns;

use Colopl\Spanner\TimestampBound\TimestampBoundInterface;
use Generator;
use Throwable;

trait ManagesStaleReads
{
    /**
     * @param string $query
     * @param array<string, mixed> $bindings
     * @param TimestampBoundInterface|null $timestampBound
     * @return Generator
     */
    public function cursorWithTimestampBound($query, $bindings = [], TimestampBoundInterface $timestampBound = null): Generator
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($timestampBound) {
            if ($this->pretending()) {
                return call_user_func(function() { yield from []; });
            }

            $options = ['parameters' => $this->prepareBindings($bindings)];
            if ($timestampBound) {
                $options = array_merge($options, $timestampBound->transactionOptions());
            }

            return $this->getSpannerDatabase()
                ->execute($query, $options)
                ->rows();
        });
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @param  TimestampBoundInterface $timestampBound
     * @return array
     * @throws Throwable
     */
    public function selectWithTimestampBound($query, $bindings = [], TimestampBoundInterface $timestampBound = null): array
    {
        return $this->withSessionNotFoundHandling(function () use ($query, $bindings, $timestampBound) {
            return iterator_to_array($this->cursorWithTimestampBound($query, $bindings, $timestampBound));
        });
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @param  TimestampBoundInterface $timestampBound
     * @return array|null
     * @throws Throwable
     */
    public function selectOneWithTimestampBound($query, $bindings = [], TimestampBoundInterface $timestampBound = null): ?array
    {
        $result = $this->withSessionNotFoundHandling(function () use ($query, $bindings, $timestampBound) {
            return $this->cursorWithTimestampBound($query, $bindings, $timestampBound)->current();
        });
        assert(is_null($result) || is_array($result));
        return $result;
    }
}

