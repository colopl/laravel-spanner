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
use Google\Cloud\Core\Exception\NotFoundException;
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

    protected bool $ignoreSessionNotFoundErrorOnRollback = false;

    /**
     * @template T
     * @param  Closure(static): T $callback
     * @param  int $attempts
     * @return T
     */
    public function transaction(Closure $callback, $attempts = Database::MAX_RETRIES)
    {
        return $this->sessionNotFoundWrapper(function () use ($callback, $attempts) {
            // Since Cloud Spanner does not support nested transactions,
            // we use Laravel's transaction management for nested transactions only.
            if ($this->transactions > 0) {
                return parent::transaction($callback, $attempts);
            }

            $return = $this->getSpannerDatabase()->runTransaction(function (Transaction $tx) use ($callback) {
                try {
                    $this->currentTransaction = $tx;
                    $this->transactions++;
                    $this->fireConnectionEvent('beganTransaction');
                    $result = $callback($this);
                    $this->performSpannerCommit();
                    return $result;
                } catch (NotFoundException $e) {
                    // if session is lost, there is no way to rollback transaction at all,
                    // so quietly ignore 'session not found' error
                    // and then abort current transaction and rerun everything again
                    $savedIgnoreError = $this->ignoreSessionNotFoundErrorOnRollback;
                    $this->ignoreSessionNotFoundErrorOnRollback = !empty($this->getSessionNotFoundMode())
                        && $this->causedBySessionNotFound($e);

                    try {
                        $this->rollBack();
                        throw $e;
                    } finally {
                        $this->ignoreSessionNotFoundErrorOnRollback = $savedIgnoreError;
                    }
                } catch (AbortedException $e) {
                    // if aborted was caused by session not found, then ignore errors on rollback
                    $savedIgnoreError = $this->ignoreSessionNotFoundErrorOnRollback;
                    $this->ignoreSessionNotFoundErrorOnRollback = !empty($this->getSessionNotFoundMode())
                        && $e->hasServiceException()
                        && $this->causedBySessionNotFound($e->getServiceException());

                    try {
                        $this->rollBack();
                        throw $e;
                    } finally {
                        $this->ignoreSessionNotFoundErrorOnRollback = $savedIgnoreError;
                    }
                } catch (Throwable $e) {
                    $this->rollBack();
                    throw $e;
                }
            }, ['maxRetries' => $attempts - 1]);

            $this->fireConnectionEvent('committed');

            return $return;
        });
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
        if ($this->transactions === 0) {
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
        }

        throw $e;
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
        $this->performSpannerCommit();
        $this->fireConnectionEvent('committed');
    }

    /**
     * @return void
     * @throws AbortedException
     */
    protected function performSpannerCommit()
    {
        if ($this->transactions === 1 && $this->currentTransaction !== null) {
            $this->currentTransaction->commit();
        }

        $this->transactions = max(0, $this->transactions - 1);
        if ($this->isTransactionFinished()) {
            $this->currentTransaction = null;
        }
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
                try {
                    $this->currentTransaction->rollBack();
                } catch (NotFoundException $e) {
                    if (!$this->ignoreSessionNotFoundErrorOnRollback) {
                        throw $e;
                    }
                }
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
