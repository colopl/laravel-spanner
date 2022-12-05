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

namespace Colopl\Spanner\Eloquent\Concerns;

use Colopl\Spanner\Concerns\MarksAsNotSupported;
use Illuminate\Database\Eloquent\Builder;

/**
 * Properties and methods defined here are disabled because Spanner does not
 * have an auto incrementing feature.
 */
trait DoesNotAutoIncrement
{
    use MarksAsNotSupported;

    /**
     * @inheritDoc
     */
    public function getIncrementing()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function setIncrementing($value)
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }

    /**
     * @inheritDoc
     */
    protected function insertAndSetId(Builder $query, $attributes): void
    {
        $this->markAsNotSupported('AUTO_INCREMENT');
    }
}

