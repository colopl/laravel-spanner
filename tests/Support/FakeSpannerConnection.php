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

namespace Colopl\Spanner\Tests\Support;

use Colopl\Spanner\Connection;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Transaction;
use LogicException;

/**
 * A Connection that talks to an injected {@see Database} test double instead of
 * a real Cloud Spanner backend.
 *
 * This makes it possible to assert *what options the driver sends* (such as
 * `timeoutMillis`) deterministically, without depending on a request actually
 * being slow enough to produce DEADLINE_EXCEEDED.
 */
class FakeSpannerConnection extends Connection
{
    /**
     * @param Database $fakeDatabase
     * @param array<string, mixed> $config
     * @param SpannerClient|null $fakeClient used by partitioned (data boost) queries
     */
    public function __construct(
        private Database $fakeDatabase,
        array $config = [],
        private ?SpannerClient $fakeClient = null,
    ) {
        parent::__construct('fake-instance', 'fake-database', '', $config);
    }

    /**
     * @inheritDoc
     */
    protected function getSpannerClient()
    {
        return $this->fakeClient ?? throw new LogicException('No fake SpannerClient was injected.');
    }

    /**
     * @inheritDoc
     */
    public function reconnect(): void
    {
        // No real connection is ever established.
    }

    /**
     * @inheritDoc
     */
    public function reconnectIfMissingConnection(): void
    {
        // No real connection is ever established.
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getSpannerDatabase(): Database
    {
        return $this->fakeDatabase;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'fake';
    }

    /**
     * Exposes the protected DML path so it can be asserted directly.
     *
     * @param list<mixed> $bindings
     */
    public function callExecuteDml(Transaction $transaction, string $query, array $bindings = []): int
    {
        return $this->executeDml($transaction, $query, $bindings);
    }

    /**
     * Exposes the protected batch DML path so it can be asserted directly.
     *
     * @param list<mixed> $bindings
     */
    public function callExecuteBatchDml(Transaction $transaction, string $query, array $bindings = []): int
    {
        return $this->executeBatchDml($transaction, $query, $bindings);
    }

    /**
     * Exposes the protected rollback path so it can be asserted directly.
     */
    public function callPerformRollBack(Transaction $transaction): void
    {
        $this->currentTransaction = $transaction;
        $this->performRollBack(0);
    }
}
