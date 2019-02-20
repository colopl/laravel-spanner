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

use Illuminate\Database\Query\Builder;

class Grammar extends \Illuminate\Database\Query\Grammars\Grammar
{
    /**
     * @param  Builder  $query
     * @param  string  $table
     * @return string
     */
    protected function compileFrom(Builder $query, $table)
    {
        return parent::compileFrom($query, $table).$this->compileForceIndex($query);
    }

    /**
     * @param Builder $query
     * @return string
     */
    protected function compileForceIndex(Builder $query)
    {
        $forceIndex = $query->forceIndex ?? null;
        return $forceIndex ? "@{FORCE_INDEX=$forceIndex}" : '';
    }

    /**
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '`'.str_replace('`', '``', $value).'`';
    }

    /**
     * @see https://cloud.google.com/spanner/docs/data-types#time-zones
     * @return string
     */
    public function getDateFormat()
    {
        return 'Y-m-d\TH:i:s.uP';
    }

    /**
     * @return bool
     */
    public function supportsSavepoints()
    {
        return false;
    }
}
