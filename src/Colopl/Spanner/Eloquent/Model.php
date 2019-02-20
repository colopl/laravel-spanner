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

namespace Colopl\Spanner\Eloquent;

use Colopl\Spanner\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model as BaseModel;

/**
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Model extends BaseModel
{
    use Concerns\InterleaveKeySupport,
        Concerns\DoesNotAutoIncrement;

    /**
     * @var string[]
     */
    protected $interleaveKeys = [];

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * Method overridden so that the class returns the query builder for Spanner
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new QueryBuilder(
            $connection, $connection->getQueryGrammar(), $connection->getPostProcessor()
        );
    }
}
