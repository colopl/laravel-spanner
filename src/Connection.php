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
use Colopl\Spanner\TimestampBound\TimestampBoundInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Generator;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Core\Exception\ConflictException;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Google\Cloud\Spanner\V1\TransactionOptions\IsolationLevel;
use Illuminate\Contracts\Database\Query\Expression;
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
    use Concerns\ManagesDataDefinitions;
    use Concerns\ManagesMutations;
    use Concerns\ManagesPartitionedDml;
    use Concerns\ManagesSession;
    use Concerns\ManagesSnapshots;
    use Concerns\ManagesTagging;
    use Concerns\ManagesTransactions;
    use Concerns\MarksAsNotSupported;

    /**
     * @var SpannerClient|null
     */
    protected ?SpannerClient $spannerClient = null;

    /**
     * @var Database|null
     */
    protected ?Database $spannerDatabase = null;

    /**
     * @var QueryParameterizer|null
     */
    protected ?QueryParameterizer $parameterizer = null;

    /**
     * @var float|null
     */
    protected ?float $defaultTimeoutSeconds = null;

    /**
     * @param string $instanceId instance ID
     * @param string $database
     * @param string $tablePrefix
     * @param array<string, mixed> $config
     * @param CacheItemPoolInterface|null $authCache
     * @param CacheItemPoolInterface|null $sessionCache
     */
    public function __construct(
        protected string $instanceId,
        string $database,
        $tablePrefix = '',
        array $config = [],
        protected ?CacheItemPoolInterface $authCache = null,
        protected ?CacheItemPoolInterface $sessionCache = null,
    ) {
        parent::__construct(
            // TODO: throw error after v9
            static fn() => null,
            $database,
            $tablePrefix,
            $config,
        );

        $clientConfig = $config['client'] ?? null;
        if (is_array($clientConfig) && isset($clientConfig['requestTimeout'])) {
            assert(is_numeric($clientConfig['requestTimeout']));
            $this->defaultTimeoutSeconds = (float) $clientConfig['requestTimeout'];
        }
    }

    /**
     * @return SpannerClient
     * @throws GoogleException
     */
    protected function getSpannerClient()
    {
        $config = $this->config['client'] ?? [];
        $config['credentialsConfig']['authCache'] ??= $this->authCache;
        $config['cacheItemPool'] ??= $this->sessionCache;

        return $this->spannerClient ??= new SpannerClient($config);
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
        $isolationLevel = $this->config['isolation_level'] ?? null;
        if (is_string($isolationLevel)) {
            $connectOptions['isolationLevel'] = match (strtolower($isolationLevel)) {
                'serializable' => IsolationLevel::SERIALIZABLE,
                'repeatable read' => IsolationLevel::REPEATABLE_READ,
                default => throw new InvalidArgumentException("Invalid isolation level: {$isolationLevel}"),
            };
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
            $this->spannerDatabase = null;
        }
    }

    /**
     * @inheritDoc
     * @return QueryGrammar
     */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return new QueryGrammar($this);
    }

    /**
     * @inheritDoc
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SchemaGrammar($this);
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
     * OVERRIDDEN for return type change
     *
     * {@inheritDoc}
     *
     * @param Closure|QueryBuilder|Expression|string $table
     * @return QueryBuilder
     */
    public function table($table, $as = null): QueryBuilder
    {
        return $this->query()->from($table, $as);
    }

    /**
     * OVERRIDDEN for return type change
     *
     * {@inheritDoc}
     *
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $bindings
     * @param array<array-key, mixed> $fetchUsing
     * @return array<array-key, mixed>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        if ($fetchUsing !== []) {
            throw new InvalidArgumentException('$fetchUsing is not supported by Cloud Spanner');
        }
        return $this->selectWithOptions($query, $bindings, []);
    }

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $bindings
     * @param array<array-key, mixed> $fetchUsing
     * @return Generator<int, array<array-key, mixed>>
     * @phpstan-ignore method.childReturnType
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        if ($fetchUsing !== []) {
            throw new InvalidArgumentException('$fetchUsing is not supported by Cloud Spanner');
        }
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
        /** @var array<int, array<array-key, mixed>> */
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
     * {@inheritDoc}
     * @param array<array-key, mixed> $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        // is SELECT query
        if (0 === stripos(ltrim($query), 'select')) {
            $this->select($query, $bindings);
            return true;
        }

        // is DML query
        if (0 === stripos(ltrim($query), 'insert') ||
            0 === stripos(ltrim($query), 'update') ||
            0 === stripos(ltrim($query), 'delete')) {
            $this->affectingStatement($query, $bindings);
            return true;
        }

        // is DDL Query
        return $this->runDdlBatch([$query]) !== null;
    }

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $bindings
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
     * {@inheritDoc}
     * @return never
     */
    public function setDatabaseName($database)
    {
        $this->markAsNotSupported('setDatabaseName');
    }

    /**
     * @internal
     * {@inheritDoc}
     * @return never
     */
    public function getPdo()
    {
        $this->markAsNotSupported('PDO access');
    }

    /**
     * @internal
     * {@inheritDoc}
     * @return never
     */
    public function getReadPdo()
    {
        $this->markAsNotSupported('PDO access');
    }

    /**
     * {@inheritDoc}
     * @param array<array-key, mixed> $bindings
     * @return array<array-key, mixed>
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
            // Since Timestamp::__toString calls setTimezone() on the DateTime object,
            // we need to clone the DateTime object to avoid changing the original object.
            return ($value instanceof DateTimeImmutable)
                ? new Timestamp($value)
                : new Timestamp(DateTimeImmutable::createFromInterface($value));
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
     * {@inheritDoc}
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
     * {@inheritDoc}
     * @param array<array-key, mixed> $bindings
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
     * > If you encounter this error, create and use a new session.
     *
     * None of the layers below us recover from this:
     *
     * - NOT_FOUND is not listed in any `retryableStatusCodes` of the generated gRPC retry config.
     * - {@see Database::runTransaction()} only retries AbortedException and INTERNAL/RST_STREAM.
     * - {@see \Google\Cloud\Spanner\Session\SessionCache} refreshes the multiplexed session on a
     *   fixed 7 day timer only. It never invalidates on error, so a session that is gone
     *   server-side keeps being read back from the (process-shared, persistent) cache until the
     *   cache item expires.
     *
     * That last point is why this must exist: the multiplexed session name is persisted in a PSR-6
     * pool that outlives the process, so a single dead session would otherwise break every request
     * of every process sharing that cache for up to 7 days.
     *
     * Cases that lead to a deleted session:
     *
     * - The database is dropped and recreated (migrations, test setup, emulator restart) while a
     *   session name for the old database is still cached.
     * - A user or an admin operation (restore, move) deletes the session.
     * - The 28 day server-side lifetime of a multiplexed session elapses.
     *
     * The library for Go and Java handles this internally but PHP's does not. We asked the
     * maintainers of the PHP library to handle it, but they refused.
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
        $options['parameters'] ??= $this->prepareBindings($bindings);
        $options = $this->withDefaultTimeout($options);

        if (isset($options['dataBoostEnabled'])) {
            return $this->executePartitionedQuery($query, $options);
        }

        $tag = $this->getRequestTag();
        if ($tag !== null) {
            $options['requestOptions'] ??= [];
            assert(is_array($options['requestOptions']));
            $options['requestOptions']['requestTag'] = $tag;
        }

        if (isset($options['snapshotEnabled'])) {
            $timestamp = $options['snapshotTimestampBound'];
            assert($timestamp instanceof TimestampBoundInterface);
            return $this->snapshot($timestamp, fn() => $this->executeSnapshotQuery($query, $options));
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
        $batchOptions = $this->extractOptions($options, ['databaseRole']);
        $snapshotOptions = [
            'transactionOptions' => $this->extractOptions($options, ['strong', 'readTimestamp', 'exactStaleness']),
        ];
        $partitionOptions = $this->extractOptions($options, [
            'maxPartitions',
            'partitionSizeBytes',
            'parameters',
            'types',
            'dataBoostEnabled',
            'timeoutMillis',
        ]);

        if ($options !== []) {
            $keysString = implode(', ', array_keys($options));
            throw new LogicException("Options: {$keysString} are not supported for partitioned queries.");
        }

        $snapshot = $this->getSpannerClient()
            ->batch($this->instanceId, $this->database, $batchOptions)
            ->snapshot($snapshotOptions);

        foreach ($snapshot->partitionQuery($query, $partitionOptions) as $partition) {
            foreach ($snapshot->executePartition($partition) as $row) {
                /** @var array<array-key, mixed> $row */
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
        $executeOptions = $this->extractOptions($options, [
            'parameters',
            'types',
            'queryOptions',
            'requestOptions',
            'directedReadOptions',
            'headers',
            'partitionToken',
            'retrySettings',
            'timeoutMillis',
            'transportOptions',
        ]);

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
        $options = $this->withDefaultTimeout(['parameters' => $this->prepareBindings($bindings)]);

        $rowCount = $transaction->executeUpdate($query, $options);
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
        $result = $transaction->executeUpdateBatch(
            [['sql' => $query, 'parameters' => $this->prepareBindings($bindings)]],
            $this->withDefaultTimeout([]),
        );

        $error = $result->error();
        if ($error !== null) {
            throw new ConflictException(
                $error['status']['message'] ?? '',
                $error['status']['code'] ?? 0,
                null,
                ['details' => $error['details'] ?? []],
            );
        }

        $rowCount = array_sum($result->rowCounts());
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
        $this->refreshSession();
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

    /**
     * @param array<string, mixed> $options
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    protected function extractOptions(array &$options, array $keys)
    {
        $extracted = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $options)) {
                $extracted[$key] = $options[$key];
                unset($options[$key]);
            }
        }
        return $extracted;
    }

    protected function calculateDefaultTimeoutMillis(): ?int
    {
        if ($this->defaultTimeoutSeconds === null) {
            return null;
        }

        $timeoutSeconds = $this->defaultTimeoutSeconds;
        $timeoutMillis = (int) ($timeoutSeconds * 1000);
        if ($timeoutMillis <= 0) {
            throw new LogicException('Request timeout must be >= 1ms.');
        }
        return $timeoutMillis;
    }

    /**
     * Applies the connection's default `client.requestTimeout` as `timeoutMillis`
     * to any RPC options that don't already specify one.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function withDefaultTimeout(array $options): array
    {
        $options['timeoutMillis'] ??= $this->calculateDefaultTimeoutMillis();
        return $options;
    }
}
