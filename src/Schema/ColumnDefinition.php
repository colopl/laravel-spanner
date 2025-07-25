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

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition as BaseColumnDefinition;

/**
 * @property string $name
 * @property string $type
 * @property string|null $arrayType
 * @property bool|null $nullable
 * @property bool|null $invisible
 * @property mixed $default
 * @property int|null $length
 * @property int|null $precision
 * @property int|null $scale
 * @property bool|null $useCurrent
 * @property bool|null $autoIncrement
 * @property string|Expression|true|null $generatedAs
 * @property string|null $virtualAs
 * @property bool|null $storedAs
 * @property int|null $startingValue
 * @property bool|null $primary
 * @property bool|null $change
 */
class ColumnDefinition extends BaseColumnDefinition
{
}
