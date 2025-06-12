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

use BackedEnum;
use Illuminate\Database\Grammar;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait SharedGrammarCalls
{
    /**
     * @see Grammar::getDateFormat()
     * @see https://cloud.google.com/spanner/docs/data-types#time-zones
     */
    public function getDateFormat()
    {
        return 'Y-m-d\TH:i:s.uP';
    }

    /**
     * @inheritDoc
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * @param array<string, scalar|BackedEnum> $options
     * @param string $delimiter
     * @return string
     */
    protected function formatOptions(array $options, string $delimiter = '='): string
    {
        $mapped = Arr::map($options, function (int|float|bool|string|BackedEnum $v, string $k) use ($delimiter): string {
            return Str::snake($k) . $delimiter . $this->formatOptionValue($v);
        });
        return implode(', ', $mapped);
    }

    /**
     * @param scalar|BackedEnum $value
     * @return string
     */
    protected function formatOptionValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $this->quoteString($value),
            $value instanceof BackedEnum => $this->formatOptionValue($value->value),
            default => (string) $value,
        };
    }
}
