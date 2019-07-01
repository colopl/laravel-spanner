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

use Closure;
use Exception;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Transaction;
use Throwable;

/**
 * Transaction extensions for Cloud Spanner based on Laravel's transaction management
 *
 * @see \Illuminate\Database\Concerns\ManagesTransactions
 */
trait ManagesTransactions
{
    /**
     * @var Transaction|null
     */
    protected $currentTransaction;

    /**
     * Execute a Closure within a transaction.
     *
     * @param  Closure  $callback
     * @param  int  $attempts
     * @return mixed
     * @throws Throwable
     */
    public function transaction(Closure $callback, $attempts = Database::MAX_RETRIES)
    {
        // Since Cloud Spanner does not support nested transactions,
        // we use Laravel's transaction management for nested transactions only.
        if ($this->transactions > 0) {
            return parent::transaction($callback, $attempts);
        }

        return $this->getSpannerDatabase()->runTransaction(function (Transaction $tx) use ($callback) {
            try {
                $this->currentTransaction = $tx;
                $this->transactions++;
                $this->fireConnectionEvent('beganTransaction');
                $result = $callback($this);
                $this->commit();
                return $result;
            } catch (Throwable $e) {
                $this->rollBack();
                throw $e;
            }
        }, ['maxRetries' => $attempts - 1]);
    }

    /**
     * @return Transaction|null
     * @internal
     */
    public function getCurrentTransaction()
    {
        return $this->currentTransaction;
    }

    /**
     * Create a transaction within the database.
     *
     * @return Transaction|null
     * @throws Exception
     */
    protected function createTransaction()
    {
        if ($this->transactions == 0) {
            try {
                $this->reconnectIfMissingConnection();
                $this->currentTransaction = $this->getSpannerDatabase()->transaction();
            } catch (Exception $e) {
                $this->currentTransaction = $this->handleBeginTransactionException($e);
            }
        }
        return $this->currentTransaction;
    }

    /**
     * Handle an exception from a transaction beginning.
     *
     * @param  Exception  $e
     * @return Transaction|null
     * @throws Exception
     */
    protected function handleBeginTransactionException($e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->reconnect();

            return $this->getSpannerDatabase()->transaction();
        } else {
            throw $e;
        }
    }

    /**
     * Commit the active database transaction.
     *
     * @return void
     * @throws AbortedException
     * @deprecated Use self::transaction() instead
     */
    public function commit()
    {
        if ($this->transactions == 1) {
            $this->currentTransaction->commit();
        }

        $this->transactions = max(0, $this->transactions - 1);
        if ($this->isTransactionFinished()) {
            $this->currentTransaction = null;
        }

        $this->fireConnectionEvent('committed');
    }

    /**
     * Perform a rollback within the database.
     *
     * @param  int  $toLevel
     * @return void
     */
    protected function performRollBack($toLevel)
    {
        if ($toLevel !== 0) {
            return;
        }

        if ($this->currentTransaction !== null) {
            if ($this->currentTransaction->state() === Transaction::STATE_ACTIVE) {
                $this->currentTransaction->rollBack();
            }
            $this->currentTransaction = null;
        }
    }

    /**
     * @return bool
     */
    public function inTransaction()
    {
        return $this->currentTransaction !== null;
    }

    /**
     * @return bool
     */
    protected function isTransactionFinished()
    {
        return $this->inTransaction() && $this->transactions === 0;
    }

    /**
     * Taken and modified from the original ManagesTransactions trait.
     * Unlike MySQL all error cases including deadlocks are thrown as
     * AbortedException so causedByDeadlock will not be called here.
     *
     * @param  Exception  $e
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @throws Exception
     */
    protected function handleTransactionException($e, $currentAttempt, $maxAttempts)
    {
        if ($this->transactions > 1) {
            $this->transactions--;

            throw $e;
        }

        $this->rollBack();

        throw $e;
    }
}
