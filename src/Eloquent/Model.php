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

use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

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
     * @param BaseModel|Relation $query
     * @param mixed $value
     * @param string|null $field
     * @return Relation
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        // value needs to be casted to prevent "No matching signature" error.
        // Ex: if table is INT64 and value is string it would throw this error.
        $key = $field ?? $this->getRouteKeyName();
        return parent::resolveRouteBindingQuery($query, $this->tryCastAttribute($key, $value), $field);
    }

    /**
     * @param  string  $childType
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Relation
     */
    protected function resolveChildRouteBindingQuery($childType, $value, $field)
    {
        $relationship = $this->{Str::plural(Str::camel($childType))}();
        $key = $field ?: $relationship->getRelated()->getRouteKeyName();
        return parent::resolveChildRouteBindingQuery($childType, $this->tryCastAttribute($key, $value), $field);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function tryCastAttribute(string $key, $value)
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $this->castAttribute($key, $value);
        }

        if ($key === $this->getKeyName() && $this->getKeyType() === 'int') {
            return (int) $value;
        }

        return $value;
    }
}
