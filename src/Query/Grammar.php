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

use Colopl\Spanner\Concerns\MarksAsNotSupported;
use Colopl\Spanner\Concerns\SharedGrammarCalls;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use Illuminate\Support\Str;
use RuntimeException;

class Grammar extends BaseGrammar
{
    use MarksAsNotSupported;
    use SharedGrammarCalls;

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $values
     */
    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        return Str::replaceFirst('insert', 'insert or ignore', $this->compileInsert($query, $values));
    }

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $values
     * @param list<string> $uniqueBy
     * @param array<array-key, mixed> $update
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        return Str::replaceFirst('insert', 'insert or update', $this->compileInsert($query, $values));
    }

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $values
     * @param string|null $sequence
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        return $this->compileInsert($query, $values) . ' then return ' . $this->wrap($sequence ?? 'id');
    }

    /**
     * @inheritDoc
     */
    protected function compileLock(Builder $query, $value)
    {
        return $value === true ? 'for update' : '';
    }

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $bindings
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        $bindings = parent::prepareBindingsForUpdate($bindings, $values);
        foreach ($bindings as $key => $value) {
            if ($value instanceof ArrayValue) {
                $bindings[$key] = $value->value;
            }
        }
        return $bindings;
    }

    /**
     * {@inheritDoc}
     * @return non-empty-array<string, array>
     */
    public function compileTruncate(Builder $query)
    {
        return ['delete from ' . $this->wrapTable($query->from) . ' where true' => []];
    }

    /**
     * @param Builder $query
     * @param IndexHint $indexHint
     * @return string
     */
    protected function compileIndexHint(Builder $query, $indexHint)
    {
        if ($indexHint->index === null) {
            return '';
        }

        $statements = [];

        $statements[] = match ($indexHint->type) {
            'force' => "FORCE_INDEX={$indexHint->index}",
            default => $this->markAsNotSupported('index type: ' . $indexHint->type),
        };

        if ($indexHint->disableEmulatorNullFilteredIndexCheck) {
            $statements[] = 'spanner_emulator.disable_query_null_filtered_index_check=true';
        }

        return '@{' . implode(',', $statements) . '}';
    }

    /**
     * @param Builder $query
     * @param array<string, Expression|string> $where
     * @return string
     */
    protected function whereInArray(Builder $query, $where)
    {
        return '? in unnest(' . $this->wrap($where['column']) . ')';
    }

    /**
     * @param Builder $query
     * @param array{ values: Nested, column: string, not: bool } $where
     * @return string
     */
    protected function whereInUnnest(Builder $query, $where)
    {
        $values = $where['values'];

        /**
         * Note: Additional inspection due to inability to constrain language level.
         * @phpstan-ignore instanceof.alwaysTrue
         */
        if (!($values instanceof Nested)) {
            throw new RuntimeException('Invalid Type:' . get_class($values) . ' given. ' . Nested::class . ' expected.');
        }

        if (count($values) <= 0) {
            return '0 = 1';
        }

        return $this->wrap($where['column'])
            . ($where['not'] ? ' not' : '')
            . ' in unnest(?)';
    }

    /**
     * @param Builder $query
     * @param array{ tokens: string, query: string, options: array<string, scalar> } $where
     * @return string
     */
    protected function whereSearchFullText(Builder $query, array $where): string
    {
        return $this->buildSearchFunction('search', $where);
    }

    /**
     * @param Builder $query
     * @param array{ tokens: string, query: string, options: array<string, scalar> } $where
     * @return string
     */
    protected function whereSearchNgrams(Builder $query, array $where): string
    {
        return $this->buildSearchFunction('search_ngrams', $where);
    }

    /**
     * @param Builder $query
     * @param array{ tokens: string, query: string, options: array<string, scalar> } $where
     * @return string
     */
    protected function whereSearchSubstring(Builder $query, array $where): string
    {
        return $this->buildSearchFunction('search_substring', $where);
    }

    /**
     * @param string $function
     * @param array{ tokens: string, query: string, options: array<string, scalar> } $where
     * @return string
     */
    protected function buildSearchFunction(string $function, array $where): string
    {
        $tokens = $this->wrap($where['tokens']);
        $rawQuery = $where['query'];
        $options = $where['options'];
        return $function . '(' . implode(', ', array_filter([
            $tokens,
            $this->quoteString($rawQuery),
            $this->formatOptions($options, ' => '),
        ])) . ')';
    }

    /**
     * @inheritDoc
     */
    public function supportsSavepoints()
    {
        return false;
    }
}
