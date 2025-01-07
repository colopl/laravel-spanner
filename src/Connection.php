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

namespace Colopl\Spanner;

use Closure;
use Colopl\Spanner\Query\Builder as QueryBuilder;
use Colopl\Spanner\Query\Grammar as QueryGrammar;
use Colopl\Spanner\Query\Nested;
use Colopl\Spanner\Query\Parameterizer as QueryParameterizer;
use Colopl\Spanner\Query\Processor as QueryProcessor;
use Colopl\Spanner\Schema\Builder as SchemaBuilder;
use Colopl\Spanner\Schema\Grammar as SchemaGrammar;
use DateTimeInterface;
use Exception;
use Generator;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Core\Exception\ConflictException;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\Query\Grammars\Grammar as BaseQueryGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Throwable;

class Connection extends BaseConnection
{
    use Concerns\ManagesDataDefinitions,
        Concerns\ManagesMutations,
        Concerns\ManagesPartitionedDml,
        Concerns\ManagesSessionPool,
        Concerns\ManagesSnapshots,
        Concerns\ManagesTagging,
        Concerns\ManagesTransactions,
        Concerns\MarksAsNotSupported;

    /**
     * @var string
     */
    protected $instanceId;

    /**
     * @var SpannerClient
     */
    protected $spannerClient;

    /**
     * @var Database|null
     */
    protected $spannerDatabase;

    /**
     * @var QueryParameterizer|null
     */
    protected $parameterizer;

    /**
     * @var CacheItemPoolInterface|null
     */
    protected $authCache;

    /**
     * @var SessionPoolInterface|null
     */
    protected $sessionPool;

    /**
     * @param string $instanceId instance ID
     * @param string $database
     * @param string $tablePrefix
     * @param array<string, mixed> $config
     * @param CacheItemPoolInterface|null $authCache
     * @param SessionPoolInterface|null $sessionPool
     */
    public function __construct(
        string $instanceId,
        string $database,
        $tablePrefix = '',
        array $config = [],
        ?CacheItemPoolInterface $authCache = null,
        ?SessionPoolInterface $sessionPool = null,
    ) {
        $this->instanceId = $instanceId;
        $this->authCache = $authCache;
        $this->sessionPool = $sessionPool;
        parent::__construct(
            // TODO: throw error after v9
            static fn() => null,
            $database,
            $tablePrefix,
            $config,
        );
    }

    /**
     * @return SpannerClient
     * @throws GoogleException
     */
    protected function getSpannerClient()
    {
        if ($this->spannerClient === null) {
            $clientConfig = $this->config['client'] ?? [];
            if ($this->authCache !== null) {
                $clientConfig = array_merge($clientConfig, ['authCache' => $this->authCache]);
            }
            $this->spannerClient = new SpannerClient($clientConfig);
        }
        return $this->spannerClient;
    }

    /**
     * @return Database
     */
    public function getSpannerDatabase(): Database
    {
        $this->reconnectIfMissingConnection();
        return $this->spannerDatabase ?? throw new LogicException('Spanner Database does not exist');
    }

    /**
     * @deprecated will be removed in v10
     * @return Database|Transaction
     */
    protected function getDatabaseContext(): Database|Transaction
    {
        return $this->getCurrentTransaction() ?? $this->getSpannerDatabase();
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->spannerDatabase !== null;
    }

    /**
     * @inheritDoc
     */
    public function reconnect()
    {
        $this->disconnect();
        $connectOptions = [];
        if ($this->sessionPool !== null) {
            $connectOptions = array_merge($connectOptions, ['sessionPool' => $this->sessionPool]);
        }
        $this->spannerDatabase = $this->getSpannerClient()->connect($this->instanceId, $this->database, $connectOptions);
    }

    /**
     * @inheritDoc
     */
    public function reconnectIfMissingConnection()
    {
        if ($this->spannerDatabase === null) {
            $this->reconnect();
        }
    }

    /**
     * @inheritDoc
     */
    public function disconnect()
    {
        if ($this->spannerDatabase !== null) {
            $this->spannerDatabase->close();
            $this->spannerDatabase = null;
        }
    }

    /**
     * @inheritDoc
     * @return QueryGrammar
     */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        $grammar = new QueryGrammar();
        $grammar->setConnection($this);
        $this->withTablePrefix($grammar);
        return $grammar;
    }

    /**
     * @inheritDoc
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        $grammar = new SchemaGrammar();
        $grammar->setConnection($this);
        $this->withTablePrefix($grammar);
        return $grammar;
    }

    /**
     * @inheritDoc
     * @return SchemaBuilder
     */
    public function getSchemaBuilder()
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * @inheritDoc
     * @return QueryProcessor
     */
    protected function getDefaultPostProcessor(): QueryProcessor
    {
        return new QueryProcessor();
    }

    /**
     * @inheritDoc OVERRIDDEN for return type change
     */
    public function table($table, $as = null): QueryBuilder
    {
        return $this->query()->from($table, $as);
    }

    /**
     * @inheritDoc OVERRIDDEN for return type change
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * @inheritDoc
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        return $this->selectWithOptions($query, $bindings, []);
    }

    /**
     * {@inheritDoc}
     * @return Generator<int, array<array-key, mixed>>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): Generator
    {
        return $this->cursorWithOptions($query, $bindings, []);
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $bindings
     * @param array<string, mixed> $options
     * @return array<int, array<array-key, mixed>>
     */
    public function selectWithOptions(string $query, array $bindings, array $options): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($options): array {
            return !$this->pretending()
                ? iterator_to_array($this->executeQuery($query, $bindings, $options))
                : [];
        });
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $bindings
     * @param array<string, mixed> $options
     * @return Generator<int, array<array-key, mixed>>
     */
    public function cursorWithOptions(string $query, array $bindings, array $options): Generator
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($options): Generator {
            return !$this->pretending()
                ? $this->executeQuery($query, $bindings, $options)
                : (static fn() => yield from [])();
        });
    }

    /**
     * @inheritDoc
     */
    public function statement($query, $bindings = []): bool
    {
        // is SELECT query
        if (0 === stripos(ltrim($query), 'select')) {
            return $this->select($query, $bindings) !== null;
        }

        // is DML query
        if (0 === stripos(ltrim($query), 'insert') ||
            0 === stripos(ltrim($query), 'update') ||
            0 === stripos(ltrim($query), 'delete')) {
            return $this->affectingStatement($query, $bindings) !== null;
        }

        // is DDL Query
        return $this->runDdlBatch([$query]) !== null;
    }

    /**
     * @inheritDoc
     */
    public function affectingStatement($query, $bindings = []): int
    {
        /** @var Closure(): int $runQueryCall */
        $runQueryCall = function () use ($query, $bindings) {
            return $this->run($query, $bindings, function ($query, $bindings) {
                if ($this->pretending()) {
                    return 0;
                }

                $transaction = $this->getCurrentTransaction();

                if ($transaction === null) {
                    throw new RuntimeException('Tried to run update outside of transaction! Affecting statements must be done inside a transaction');
                }

                return $this->shouldRunAsBatchDml($query)
                    ? $this->executeBatchDml($transaction, $query, $bindings)
                    : $this->executeDml($transaction, $query, $bindings);
            });
        };

        if ($this->pretending()) {
            return $runQueryCall();
        }

        if ($this->inTransaction()) {
            return $runQueryCall();
        }

        // Create a temporary transaction for single affecting statement
        return $this->transaction($runQueryCall);
    }

    /**
     * @inheritDoc
     */
    public function unprepared($query): bool
    {
        return $this->statement($query);
    }

    /**
     * @inheritDoc
     */
    public function getDatabaseName()
    {
        return $this->getSpannerDatabase()->name();
    }

    /**
     * @internal
     * @inheritDoc
     * @return void
     */
    public function setDatabaseName($database)
    {
        $this->markAsNotSupported('setDatabaseName');
    }

    /**
     * @internal
     * @inheritDoc
     * @return void
     * @internal
     */
    public function getPdo()
    {
        $this->markAsNotSupported('PDO access');
    }

    /**
     * @internal
     * @inheritDoc
     * @return void
     * @internal
     */
    public function getReadPdo()
    {
        $this->markAsNotSupported('PDO access');
    }

    /**
     * TODO: Remove in v9
     * @deprecated Parent method no longer exists. This will be removed in v9.
     * @return never
     */
    public function getDoctrineConnection()
    {
        $this->markAsNotSupported('Doctrine');
    }

    /**
     * @inheritDoc
     */
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            $bindings[$key] = $this->prepareBinding($grammar, $value);
        }

        return $bindings;
    }

    protected function prepareBinding(BaseQueryGrammar $grammar, mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        // We need to transform all instances of DateTimeInterface into the actual
        // date string. Each query grammar maintains its own date string format
        // so we'll just ask the grammar for the format to get from the date.
        if ($value instanceof DateTimeInterface) {
            return new Timestamp($value);
        }

        if (is_array($value)) {
            $arr = [];
            foreach ($value as $k => $v) {
                $arr[$k] = $this->prepareBinding($grammar, $v);
            }
            return $arr;
        }

        return $value;
    }

    /**
     * @inheritDoc
     * @param scalar|list<mixed>|Nested|null $value
     */
    public function escape($value, $binary = false)
    {
        if ($value instanceof Nested) {
            $value = $value->toArray();
        }

        return is_array($value)
            ? $this->escapeArray($value, $binary)
            : parent::escape($value, $binary);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param bool $binary
     * @return string
     */
    protected function escapeArray(array $value, bool $binary): string
    {
        if (array_is_list($value)) {
            $escaped = array_map(function (mixed $v) use ($binary): string {
                return is_scalar($v)
                    ? $this->escape($v, $binary)
                    : throw new LogicException('Nested arrays are not supported by Cloud Spanner');
            }, $value);
            return '[' . implode(', ', $escaped) . ']';
        }
        throw new LogicException('Associative arrays are not supported');
    }

    /**
     * @inheritDoc
     */
    protected function escapeBool($value)
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @inheritDoc
     */
    protected function escapeString($value)
    {
        return str_contains($value, "\n")
            ? 'r"""' . addcslashes($value, '"\\') . '"""'
            : '"' . addcslashes($value, '"\\') . '"';
    }

    /**
     * @inheritDoc
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        $this->parameterizer = $this->parameterizer ?? new QueryParameterizer();
        [$query, $bindings] = $this->parameterizer->parameterizeQuery($query, $bindings);

        try {
            $result = $this->withSessionNotFoundHandling(function () use ($query, $bindings, $callback) {
                return $callback($query, $bindings);
            });
        }

        // AbortedExceptions are expected to be thrown upstream by the Google Client Library upstream,
        // so AbortedExceptions will not be wrapped with QueryException.
        catch (AbortedException $e) {
            throw $e;
        }

        // If an exception occurs when attempting to run a query, we'll format the error
        // message to include the bindings with SQL, which will make this exception a
        // lot more helpful to the developer instead of just the database's errors.
        catch (Exception $e) {
            throw new QueryException(
                $this->getName() ?? 'unknown',
                $query,
                $this->prepareBindings($bindings),
                $e,
            );
        }

        return $result;
    }

    /**
     * Retry on "session not found" errors
     *
     * @see https://cloud.google.com/spanner/docs/sessions#handle_deleted_sessions
     *
     * > Attempts to use a deleted session result in NOT_FOUND.
     * > If you encounter this error, create and use a new session, add the new session to the pool,
     * > and remove the deleted session from the pool.
     *
     * Most cases are covered by Google's library except for the following two cases.
     *
     * - When a connection is opened, and idles for more than 1 hour.
     * - If a user manually deletes a session from the console.
     *
     * The document states that the library should be handling this, and library for Go and Java
     * handles this within the library but PHP's does not. So unfortunately, this code has to exist.
     *
     * We asked the maintainers of the PHP library to handle it, but they refused.
     * https://github.com/googleapis/google-cloud-php/issues/6284.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     * @throws AbortedException|NotFoundException|InvalidArgumentException
     */
    protected function withSessionNotFoundHandling(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if (!$this->inTransaction() && $this->causedBySessionNotFound($e)) {
                return $this->handleSessionNotFoundException($callback);
            }
            throw $e;
        }
    }

    /**
     * @param string $query
     * @param array<array-key, mixed> $bindings
     * @param array<string, mixed> $options
     * @return Generator<int, array<array-key, mixed>>
     */
    protected function executeQuery(string $query, array $bindings, array $options): Generator
    {
        $options += ['parameters' => $this->prepareBindings($bindings)];

        if (isset($options['dataBoostEnabled'])) {
            return $this->executePartitionedQuery($query, $options);
        }

        $tag = $this->getRequestTag();
        if ($tag !== null) {
            $options['requestOptions'] ??= [];
            $options['requestOptions']['requestTag'] = $tag;
        }

        if ($this->inSnapshot()) {
            return $this->executeSnapshotQuery($query, $options);
        }

        if ($this->canExecuteAsReadWriteTransaction($options) && $transaction = $this->getCurrentTransaction()) {
            return $transaction->execute($query, $options)->rows();
        }

        return $this->getSpannerDatabase()->execute($query, $options)->rows();
    }

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return Generator<int, array<array-key, mixed>>
     */
    protected function executePartitionedQuery(string $query, array $options): Generator
    {
        $snapshot = $this->getSpannerClient()
            ->batch($this->instanceId, $this->database, $options)
            ->snapshot();

        foreach ($snapshot->partitionQuery($query, $options) as $partition) {
            foreach ($snapshot->executePartition($partition) as $row) {
                yield $row;
            }
        }
    }

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return Generator<int, array<array-key, mixed>>
     */
    protected function executeSnapshotQuery(string $query, array $options): Generator
    {
        $executeOptions = Arr::only($options, ['parameters', 'types', 'queryOptions', 'requestOptions']);
        assert($this->currentSnapshot !== null);
        return $this->currentSnapshot->execute($query, $executeOptions)->rows();
    }

    /**
     * @param Transaction $transaction
     * @param string $query
     * @param list<mixed> $bindings
     * @return int
     */
    protected function executeDml(Transaction $transaction, string $query, array $bindings = []): int
    {
        $rowCount = $transaction->executeUpdate($query, ['parameters' => $this->prepareBindings($bindings)]);
        $this->recordsHaveBeenModified($rowCount > 0);
        return $rowCount;
    }

    /**
     * @param Transaction $transaction
     * @param string $query
     * @param list<mixed> $bindings
     * @return int
     */
    protected function executeBatchDml(Transaction $transaction, string $query, array $bindings = []): int
    {
        $result = $transaction->executeUpdateBatch([
            ['sql' => $query, 'parameters' => $this->prepareBindings($bindings)],
        ]);

        $error = $result->error();
        if ($error !== null) {
            throw new ConflictException(
                $error['status']['message'] ?? '',
                $error['status']['code'] ?? 0,
                null,
                ['details' => $error['details'] ?? []],
            );
        }

        $rowCount = array_sum($result->rowCounts() ?? []);
        $this->recordsHaveBeenModified($rowCount > 0);
        return $rowCount;
    }

    /**
     * @param array<string, mixed> $options
     * @return bool
     */
    protected function canExecuteAsReadWriteTransaction(array $options): bool
    {
        $readOnlyTriggers = [
            'singleUse',
            'exactStaleness',
            'maxStaleness',
            'minReadTimestamp',
            'readTimestamp',
            'strong',
        ];

        foreach ($readOnlyTriggers as $option) {
            if ($options[$option] ?? false) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $query
     * @return bool
     */
    protected function shouldRunAsBatchDml(string $query): bool
    {
        return stripos($query, 'insert or ') === 0;
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    protected function handleSessionNotFoundException(Closure $callback): mixed
    {
        $this->disconnect();
        // Currently, there is no way for us to delete the session, so we have to delete the whole pool.
        // This might affect parallel processes.
        $this->clearSessionPool();
        $this->reconnect();
        return $callback();
    }

    /**
     * Check if this is "session not found" error
     *
     * @param Throwable $e
     * @return bool
     */
    protected function causedBySessionNotFound(Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            $e = $e->getPrevious();
        }

        return ($e instanceof NotFoundException)
            && str_contains($e->getMessage(), 'Session does not exist');
    }

}
