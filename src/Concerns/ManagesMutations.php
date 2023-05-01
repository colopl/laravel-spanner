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
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * @phpstan-type TKeySet scalar|KeySet
 * @method Database|Transaction getDatabaseContext()
 */
trait ManagesMutations
{
    public function insertUsingMutation(string $table, array $dataSet): void
    {
        $this->withTransactionEvents(function () use ($table, $dataSet) {
            $dataSet = $this->prepareForMutation($dataSet);
            $this->event(new MutatingData($this, $table, 'insert', $dataSet));
            $this->getDatabaseContext()->insertBatch($table, $dataSet);
        });
    }

    public function updateUsingMutation(string $table, array $dataSet): void
    {
        $this->withTransactionEvents(function () use ($table, $dataSet) {
            $dataSet = $this->prepareForMutation($dataSet);
            $this->event(new MutatingData($this, $table, 'update', $dataSet));
            $this->getDatabaseContext()->updateBatch($table, $dataSet);
        });
    }

    /**
     * @param TKeySet|list<TKeySet> $keySet
     */
    public function deleteUsingMutation(string $table, bool|string|int|float|array|KeySet $keySet): void
    {
        $this->withTransactionEvents(function () use ($table, $keySet) {
            $keySet = $this->createDeleteMutationKeySet($keySet);
            $dataSet = $keySet->keys() ?: $keySet->keySetObject();
            $this->event(new MutatingData($this, $table, 'delete', $dataSet));
            $this->getDatabaseContext()->delete($table, $keySet);
        });
    }

   private function withTransactionEvents(callable $mutationCall): void
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

    private function prepareForMutation(array $dataSet): array
    {
        if (0 >= count($dataSet)) {
            return [];
        }

        if (Arr::isAssoc($dataSet)) {
            $dataSet = [$dataSet];
        }

        foreach ($dataSet as $index => $values) {
            foreach ($values as $name => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $dataSet[$index][$name] = new Timestamp($value);
                }
            }
        }

        return $dataSet;
    }

    /**
     * @param TKeySet|list<TKeySet> $keys
     * @return KeySet
     */
    private function createDeleteMutationKeySet($keys): KeySet
    {
        if ($keys instanceof KeySet) {
            return $keys;
        }

        if (is_object($keys)) {
            throw new InvalidArgumentException('delete should contain array of keys or be instance of KeySet. '.$keys::class.' given.');
        }

        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return new KeySet(['keys' => $keys]);
    }
}
