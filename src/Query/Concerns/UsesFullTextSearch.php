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

trait UsesFullTextSearch
{
    /**
     * @param string $tokens
     * @param string $query
     * @param array<string, scalar> $options
     * @param string $boolean
     * @return $this
     */
    public function searchFullText(
        string $tokens,
        string $query,
        array $options = [],
        string $boolean = 'and',
    ): static {
        $this->addSearchCondition('SearchFullText', $tokens, $query, $options, $boolean);
        return $this;
    }

    /**
     * @param string $tokens
     * @param string $query
     * @param array<string, scalar> $options
     * @param string $boolean
     * @return $this
     */
    public function searchNgrams(
        string $tokens,
        string $query,
        array $options = [],
        string $boolean = 'and',
    ): static {
        $this->addSearchCondition('SearchNgrams', $tokens, $query, $options, $boolean);
        return $this;
    }

    /**
     * @param string $tokens
     * @param string $query
     * @param array<string, scalar> $options
     * @param string $boolean
     * @return $this
     */
    public function searchSubstring(
        string $tokens,
        string $query,
        array $options = [],
        string $boolean = 'and',
    ): static {
        $this->addSearchCondition('SearchSubstring', $tokens, $query, $options, $boolean);
        return $this;
    }

    /**
     * @param string $type
     * @param string $tokens
     * @param string $query
     * @param array<string, scalar> $options
     * @param string $boolean
     * @return void
     */
    protected function addSearchCondition(
        string $type,
        string $tokens,
        string $query,
        array $options = [],
        string $boolean = 'and',
    ): void {
        $this->wheres[] = [
            'type' => $type,
            'tokens' => $tokens,
            'boolean' => $boolean,
            'query' => $query,
            'options' => $options,
        ];
        $this->addBinding($query);
    }
}
