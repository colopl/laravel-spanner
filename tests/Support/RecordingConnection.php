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

use Closure;
use Colopl\Spanner\Connection;
use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * A Connection that records every statement instead of sending it to Cloud
 * Spanner, and replays canned results back.
 *
 * Cloud Spanner queues are not implemented by the Spanner emulator, so driver
 * behaviour that would otherwise need a real instance is asserted against
 * this double.
 *
 * @phpstan-type TRecordedQuery array{
 *     sql: string,
 *     bindings: array<array-key, mixed>,
 *     options: array<string, mixed>,
 * }
 */
class RecordingConnection extends Connection
{
    /**
     * Every statement the driver issued, in order.
     *
     * @var list<TRecordedQuery>
     */
    public array $recorded = [];

    /**
     * Rows that the next `cursorWithOptions()` call streams back.
     *
     * @var list<array<array-key, mixed>>
     */
    public array $messages = [];

    /**
     * Number of times a `RECEIVE_<queue>()` stream was opened.
     */
    public int $streamsOpened = 0;

    /**
     * Rows that the next `select()` call returns.
     *
     * @var list<array<array-key, mixed>>
     */
    public array $selectResults = [];

    /**
     * Row counts that successive affecting statements report, in order.
     * Statements beyond this list report 1.
     *
     * @var list<int>
     */
    public array $affectedRows = [];

    /**
     * Number of transactions that were opened.
     */
    public int $transactionCount = 0;

    /**
     * Forces {@see self::inTransaction()} to report an open transaction.
     */
    public bool $pretendInTransaction = false;

    /**
     * @param string $tablePrefix
     * @param array<string, mixed> $config
     */
    public function __construct(string $tablePrefix = '', array $config = [])
    {
        parent::__construct('fake-instance', 'fake-database', $tablePrefix, $config);
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
    public function getName(): string
    {
        return 'fake';
    }

    /**
     * @inheritDoc
     */
    public function inTransaction(): bool
    {
        return $this->pretendInTransaction || parent::inTransaction();
    }

    /**
     * @inheritDoc
     * @param array<array-key, mixed> $bindings
     * @param array<array-key, mixed> $fetchUsing
     * @return array<int, array<array-key, mixed>>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $this->record($query, $bindings, []);

        return $this->selectResults;
    }

    /**
     * @inheritDoc
     * @param array<array-key, mixed> $bindings
     */
    public function affectingStatement($query, $bindings = []): int
    {
        $index = count($this->recorded);
        $this->record($query, $bindings, []);

        return $this->affectedRows[$index] ?? 1;
    }

    /**
     * @inheritDoc
     * @param array<array-key, mixed> $bindings
     * @param array<string, mixed> $options
     * @return Generator<int, array<array-key, mixed>>
     */
    public function cursorWithOptions(string $query, array $bindings, array $options): Generator
    {
        $this->record($query, $bindings, $options);
        $this->streamsOpened++;

        foreach ($this->messages as $message) {
            yield $message;
        }
        $this->messages = [];
    }

    /**
     * @inheritDoc
     * @template T
     * @param Closure(static): T $callback
     * @param int $attempts
     * @return T
     */
    public function transaction(Closure $callback, $attempts = -1)
    {
        $this->transactionCount++;

        return $callback($this);
    }

    /**
     * @inheritDoc
     * @param list<string> $statements
     * @return mixed
     */
    public function runDdlBatch(array $statements): mixed
    {
        foreach ($statements as $statement) {
            $this->record($statement, [], []);
        }

        return [];
    }

    /**
     * The statement at the given offset.
     *
     * @param int $index
     * @return TRecordedQuery
     */
    public function recordedAt(int $index): array
    {
        return $this->recorded[$index]
            ?? throw new InvalidArgumentException("No statement was recorded at index {$index}.");
    }

    /**
     * SQL of every recorded statement.
     *
     * @return list<string>
     */
    public function recordedSql(): array
    {
        return array_column($this->recorded, 'sql');
    }

    /**
     * @param string $sql
     * @param array<array-key, mixed> $bindings
     * @param array<string, mixed> $options
     * @return void
     */
    protected function record(string $sql, array $bindings, array $options): void
    {
        $this->recorded[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'options' => $options,
        ];
    }

    /**
     * @inheritDoc
     * @return never
     */
    public function getSpannerDatabase(): never
    {
        throw new LogicException('RecordingConnection never talks to Cloud Spanner.');
    }
}
