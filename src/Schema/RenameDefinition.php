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

use Illuminate\Support\Fluent;

/**
 * @property string $name
 * @property string $to
 * @property string|true $synonym
 * @method $this synonym(string|null $name = null)
 * @extends Fluent<string, mixed>
 */
class RenameDefinition extends Fluent
{
    /**
     * @param string $name
     * @param string $to
     */
    public function __construct(string $name, string $to)
    {
        parent::__construct(['name' => $name, 'to' => $to]);
    }
}
