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
     * {@inheritDoc}
     *
     * The base implementation only wraps the value in single quotes, so a quote or
     * backslash inside the value would end or corrupt the literal.
     *
     * @param string|array<array-key, string> $value
     * @return string
     */
    public function quoteString($value)
    {
        if (is_array($value)) {
            return implode(', ', array_map($this->quoteString(...), $value));
        }

        return "'" . $this->escapeStringLiteral($value) . "'";
    }

    /**
     * Escape a value for use inside a quoted (non-raw) GoogleSQL string literal.
     * Works for both single- and double-quoted literals.
     *
     * @see https://cloud.google.com/spanner/docs/reference/standard-sql/lexical#string_and_bytes_literals
     * @param string $value
     * @return string
     */
    protected function escapeStringLiteral(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            "'" => "\\'",
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\0" => '\\000',
        ]);
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
