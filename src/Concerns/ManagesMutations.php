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

namespace Colopl\Spanner\Concerns;

use Colopl\Spanner\Events\MutatingData;
use DateTimeInterface;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * @phpstan-type TDataSet list<array<string, mixed>>|array<string, mixed>
 */
trait ManagesMutations
{
    /**
     * @return Database|Transaction
     */
    abstract protected function getDatabaseContext(): Database|Transaction;

    /**
     * @param string $table
     * @param TDataSet $dataSet
     * @return void
     */
    public function insertUsingMutation(string $table, array $dataSet)
    {
        $this->withTransactionEvents(function () use ($table, $dataSet) {
            $dataSet = $this->prepareForMutation($dataSet);
            $this->event(new MutatingData($this, $table, 'insert', $dataSet));
            $this->getMutationExecutor()->insertBatch($table, $dataSet);
        });
    }

    /**
     * @param string $table
     * @param TDataSet $dataSet
     * @return void
     */
    public function updateUsingMutation(string $table, array $dataSet)
    {
        $this->withTransactionEvents(function () use ($table, $dataSet) {
            $dataSet = $this->prepareForMutation($dataSet);
            $this->event(new MutatingData($this, $table, 'update', $dataSet));
            $this->getMutationExecutor()->updateBatch($table, $dataSet);
        });
    }

    /**
     * @param string $table
     * @param TDataSet $dataSet
     * @return void
     */
    public function insertOrUpdateUsingMutation(string $table, array $dataSet)
    {
        $this->withTransactionEvents(function () use ($table, $dataSet) {
            $dataSet = $this->prepareForMutation($dataSet);
            $this->event(new MutatingData($this, $table, 'update', $dataSet));
            $this->getMutationExecutor()->insertOrUpdateBatch($table, $dataSet);
        });
    }

    /**
     * @param string $table
     * @param scalar|array<mixed>|KeySet $keySet
     * @return void
     */
    public function deleteUsingMutation(string $table, $keySet)
    {
        $this->withTransactionEvents(function () use ($table, $keySet) {
            $keySet = $this->createDeleteMutationKeySet($keySet);
            $dataSet = $keySet->keys() ?: $keySet->keySetObject();
            $this->event(new MutatingData($this, $table, 'delete', $dataSet));
            $this->getMutationExecutor()->delete($table, $keySet);
        });
    }

    /**
     * @return Database|Transaction
     */
    protected function getMutationExecutor(): Database|Transaction
    {
        return $this->getCurrentTransaction() ?? $this->getSpannerDatabase();
    }

    /**
     * @param callable $mutationCall
     * @return void
     */
    protected function withTransactionEvents(callable $mutationCall)
    {
        // events not necessary since it is already called
        if ($this->inTransaction()) {
            $mutationCall();
        } else {
            $this->event(new TransactionBeginning($this));
            $mutationCall();
            $this->event(new TransactionCommitted($this));
        }
    }

    /**
     * @param TDataSet $dataSet
     * @return array<array-key, iterable<array-key, mixed>>
     */
    protected function prepareForMutation(array $dataSet): array
    {
        if (empty($dataSet)) {
            return [];
        }

        if (!array_is_list($dataSet)) {
            $dataSet = [$dataSet];
        }

        foreach ($dataSet as $index => $values) {
            foreach ($values as $name => $value) {
                if ($value instanceof DateTimeInterface) {
                    $dataSet[$index][$name] = new Timestamp($value);
                }
            }
        }

        return $dataSet;
    }

    /**
     * @param mixed|list<string>|KeySet $keys
     * @return KeySet
     */
    protected function createDeleteMutationKeySet($keys)
    {
        if ($keys instanceof KeySet) {
            return $keys;
        }

        if (is_object($keys)) {
            throw new InvalidArgumentException('delete should contain array of keys or be instance of KeySet. ' . get_class($keys) . ' given.');
        }

        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return new KeySet(['keys' => $keys]);
    }
}
