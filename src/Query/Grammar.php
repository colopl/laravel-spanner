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
use Colopl\Spanner\Query\Builder as SpannerBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use RuntimeException;

class Grammar extends BaseGrammar
{
    use MarksAsNotSupported;
    use SharedGrammarCalls;

    /**
     * @inheritDoc
     */
    protected function compileFrom(Builder $query, $table)
    {
        return parent::compileFrom($query, $table).$this->compileForceIndex($query);
    }

    /**
     * @inheritDoc
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        $this->markAsNotSupported('insertGetId');
    }

    /**
     * @inheritDoc
     */
    protected function compileLock(Builder $query, $value)
    {
        $this->markAsNotSupported('explicit locking.');
    }

    /**
     * @inheritDoc
     */
    public function compileTruncate(Builder $query)
    {
        return ['delete from '.$this->wrapTable($query->from).' where true' => []];
    }

    /**
     * @param Builder $query
     * @return string
     */
    protected function compileForceIndex(Builder $query)
    {
        if ($query instanceof SpannerBuilder) {
            return $query->forceIndex ? "@{FORCE_INDEX=$query->forceIndex}" : '';
        }

        throw new RuntimeException('Unexpected Builder: '.get_class($query).' Expected: '.SpannerBuilder::class);
    }

    /**
     * @param Builder $query
     * @param array<string, Expression|string> $where
     * @return string
     */
    protected function whereInArray(Builder $query, $where)
    {
        return '? in unnest('.$this->wrap($where['column']).')';
    }

    /**
     * @param Builder $query
     * @param array $where
     * @return string
     */
    protected function whereInUnnest(Builder $query, $where)
    {
        $values = $where['values'];

        if (!($values instanceof Nested)) {
            throw new RuntimeException('Invalid Type:'.get_class($values).' given. '.Nested::class.' expected.');
        }

        return (count($values) > 0)
            ? $this->wrap($where['column']).' in unnest(?)'
            : '0 = 1';
    }

    /**
     * @inheritDoc
     */
    public function supportsSavepoints()
    {
        return false;
    }
}
