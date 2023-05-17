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
use Illuminate\Database\QueryException;
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
    protected ?Transaction $currentTransaction = null;

    protected bool $ignoreSessionNotFoundErrorOnRollback = false;

    /**
     * @inheritDoc
     * @template T
     * @param  Closure(static): T $callback
     * @param  int $attempts
     * @return T
     */
    public function transaction(Closure $callback, $attempts = Database::MAX_RETRIES)
    {
        // Since Cloud Spanner does not support nested transactions,
        // we use Laravel's transaction management for nested transactions only.
        if ($this->transactions > 0) {
            return parent::transaction($callback, $attempts);
        }

        return $this->withSessionNotFoundHandling(function () use ($callback, $attempts) {
            $return = $this->getSpannerDatabase()->runTransaction(function (Transaction $tx) use ($callback) {
                try {
                    $this->currentTransaction = $tx;

                    $this->transactions++;

                    $this->transactionsManager?->begin(
                        $this->getName(), $this->transactions
                    );

                    $this->fireConnectionEvent('beganTransaction');

                    $result = $callback($this);

                    $this->performSpannerCommit();

                    return $result;
                } catch (Throwable $e) {
                    // if session is lost, there is no way to rollback transaction at all,
                    // so quietly ignore 'session not found' error in rollBack()
                    // and then abort current transaction and rerun everything again
                    $savedIgnoreError = $this->ignoreSessionNotFoundErrorOnRollback;
                    $exceptionToCheck = $e instanceof QueryException ? $e->getPrevious() : $e;

                    $this->ignoreSessionNotFoundErrorOnRollback =
                        $exceptionToCheck instanceOf NotFoundException
                        && $this->getSessionNotFoundMode() !== self::THROW_EXCEPTION
                        && $this->causedBySessionNotFound($exceptionToCheck);

                    try {
                        $this->rollBack();
                        // rethrow NotFoundException instead of QueryException
                        $eToThrow = $this->ignoreSessionNotFoundErrorOnRollback ? $exceptionToCheck : $e;
                        // mute phpstan
                        assert($eToThrow !== null);
                        throw $eToThrow;
                    } finally {
                        $this->ignoreSessionNotFoundErrorOnRollback = $savedIgnoreError;
                    }
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
     * @inheritDoc
     */
    protected function createTransaction()
    {
        if ($this->transactions === 0) {
            try {
                $this->reconnectIfMissingConnection();
                $this->currentTransaction = $this->getSpannerDatabase()->transaction();
            } catch (Exception $e) {
                $this->handleBeginTransactionException($e);
            }
        }
    }

    /**
     * @inheritDoc
     */
    protected function handleBeginTransactionException($e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->reconnect();

            $this->currentTransaction = $this->getSpannerDatabase()->transaction();
            return;
        }

        throw $e;
    }

    /**
     * @inheritDoc
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
    protected function performSpannerCommit(): void
    {
        if ($this->transactions === 1 && $this->currentTransaction !== null) {
            $this->fireConnectionEvent('committing');
            $this->currentTransaction->commit();
        }

        $this->transactions = max(0, $this->transactions - 1);
        if ($this->isTransactionFinished()) {
            $this->currentTransaction = null;
        }

        if ($this->afterCommitCallbacksShouldBeExecuted()) {
            $this->transactionsManager?->commit($this->getName());
        }
    }

    /**
     * @inheritDoc
     */
    protected function performRollBack($toLevel)
    {
        if ($toLevel !== 0) {
            return;
        }

        if ($this->currentTransaction !== null) {
            try {
                if ($this->currentTransaction->state() === Transaction::STATE_ACTIVE) {
                    $this->currentTransaction->rollBack();
                }
            } catch (NotFoundException $e) {
                if (!$this->ignoreSessionNotFoundErrorOnRollback) {
                    throw $e;
                }
            } finally {
                $this->currentTransaction = null;
            }
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
     * @inheritDoc
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

    /**
     * @param Throwable $e
     * @return void
     */
    protected function handleRollbackException(Throwable $e)
    {
        // Must be reset so that transaction can be retried.
        // otherwise, transactions will remain at 1.
        $this->transactions = 0;

        throw $e;
    }
}
