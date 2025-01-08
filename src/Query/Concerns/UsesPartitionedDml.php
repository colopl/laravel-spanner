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

use Colopl\Spanner\Connection;
use Illuminate\Database\Query\Grammars\Grammar;

/**
 * @property Connection $connection
 * @property Grammar $grammar
 */
trait UsesPartitionedDml
{
    /**
     * @param array<string, mixed> $values
     * @return int affected rows count
     */
    public function partitionedUpdate(array $values)
    {
        $sql = $this->grammar->compileUpdate($this, $values);
        return $this->connection->runPartitionedDml($sql, $this->cleanBindings(
            $this->grammar->prepareBindingsForUpdate($this->bindings, $values),
        ));
    }

    /**
     * @return int
     */
    public function partitionedDelete()
    {
        return $this->connection->runPartitionedDml(
            $this->grammar->compileDelete($this),
            $this->cleanBindings($this->grammar->prepareBindingsForDelete($this->bindings)),
        );
    }
}
