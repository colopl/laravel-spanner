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

namespace Colopl\Spanner\Query\Concerns;

trait UsesDataBoost
{
    /**
     * @var bool
     */
    protected bool $useDataBoost = false;

    /**
     * @param bool $toggle
     * @return $this
     */
    public function useDataBoost(bool $toggle = true): static
    {
        $this->useDataBoost = $toggle;
        return $this;
    }

    /**
     * @return bool
     */
    public function dataBoostEnabled(): bool
    {
        return $this->useDataBoost;
    }
}
