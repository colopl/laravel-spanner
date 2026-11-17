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

use Colopl\Spanner\Concerns\ManagesMutations;
use Colopl\Spanner\Connection;
use Google\Cloud\Spanner\KeySet;
use Illuminate\Contracts\Database\Query\Expression;

/**
 * @phpstan-import-type TDataSet from ManagesMutations
 *
 * @property Connection $connection
 */
trait UsesMutations
{
    /**
     * @param TDataSet $values
     * @return void
     */
    public function insertUsingMutation(array $values)
    {
        $this->connection->insertUsingMutation($this->getTableName(), $values);
    }

    /**
     * @param TDataSet $values
     * @return void
     */
    public function updateUsingMutation(array $values)
    {
        $this->connection->updateUsingMutation($this->getTableName(), $values);
    }

    /**
     * @param TDataSet $values
     * @return void
     */
    public function insertOrUpdateUsingMutation(array $values)
    {
        $this->connection->insertOrUpdateUsingMutation($this->getTableName(), $values);
    }

    /**
     * @param list<string>|KeySet $keys
     * @return void
     */
    public function deleteUsingMutation($keys)
    {
        $this->connection->deleteUsingMutation($this->getTableName(), $keys);
    }

    /**
     * Mutations bypass the grammar, so the table prefix that `wrapTable()` would normally
     * add has to be applied here.
     *
     * @return string
     */
    private function getTableName(): string
    {
        // A raw expression is taken as-is, like wrapTable() does.
        if ($this->from instanceof Expression) {
            return (string) $this->getGrammar()->getValue($this->from);
        }

        return $this->connection->getTablePrefix() . $this->from;
    }
}
