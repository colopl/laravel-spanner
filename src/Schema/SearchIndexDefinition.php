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

namespace Colopl\Spanner\Schema;

/**
 * @property string $name
 * @property string $index
 * @property list<string> $columns
 * @property string|list<string> $partitionBy
 * @property list<string>|array<string, string> $orderBy
 * @property bool|null $sortOrderSharding
 * @property bool|null $disableAutomaticUidColumn
 * @method $this partitionBy(string|string[] $columns)
 * @method $this orderBy(string|string[] $columns)
 * @method $this sortOrderSharding(bool $toggle = true)
 * @method $this disableAutomaticUidColumn(bool $toggle = true)
 */
class SearchIndexDefinition extends IndexDefinition
{
    /**
     * @return array{ sortOrderSharding?: bool, disableAutomaticUidColumn?: bool }
     */
    public function getOptions(): array
    {
        return array_filter([
            'sortOrderSharding' => $this->sortOrderSharding,
            'disableAutomaticUidColumn' => $this->disableAutomaticUidColumn,
        ], static fn($v) => $v !== null);
    }
}
