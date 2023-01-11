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

namespace Colopl\Spanner\Events;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEvent;

class MutatingData extends ConnectionEvent
{
    /**
     * @var string
     */
    public $tableName;

    /**
     * @var string
     */
    public $command;

    /**
     * @var array
     */
    public $values;

    /**
     * @param Connection $connection
     * @param string $tableName
     * @param string $command
     * @param array $values
     */
    public function __construct($connection, string $tableName, string $command, array $values)
    {
        parent::__construct($connection);

        $this->tableName = $tableName;
        $this->command = $command;
        $this->values = $values;
    }

}
