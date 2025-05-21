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

use Illuminate\Database\Schema\IndexDefinition as BaseIndexDefinition;
use LogicException;

/**
 * @property string $indexType
 * @property string $index
 * @property array<string, 'desc'|'asc'>|list<string> $columns
 * @property string|null $interleaveIn
 * @property bool|null $nullFiltered
 * @property list<string>|null $storing
 * @method $this interleaveIn(string $table)
 * @method $this nullFiltered()
 * @method $this storing(string[] $columns)
 */
class IndexDefinition extends BaseIndexDefinition
{
}
