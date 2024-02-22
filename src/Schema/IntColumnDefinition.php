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

declare(strict_types=1);

namespace Colopl\Spanner\Schema;

use Illuminate\Database\Schema\ColumnDefinition;

/**
 * @property string $name
 * @property string|null $useSequence
 */
class IntColumnDefinition extends ColumnDefinition
{
    public function __construct(protected Blueprint $blueprint, $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * Set the column to use a sequence.
     *
     * @param string|null $name
     * @return $this
     */
    public function useSequence(?string $name = null): static
    {
        $this->useSequence = $name ?? $this->createDefaultSequence();
        return $this;
    }

    /**
     * @return string
     */
    protected function createDefaultSequence(): string
    {
        $definition = $this->blueprint->createSequence($this->createSequenceName());
        $definition->startWithCounter(random_int(1, 1000000));
        return $definition->sequence;
    }

    /**
     * @return string
     */
    protected function createSequenceName(): string
    {
        return $this->blueprint->getPrefix()
            . $this->blueprint->getTable()
            . '_'
            . $this->name
            . '_sequence';
    }

}
