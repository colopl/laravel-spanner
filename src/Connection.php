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
use Colopl\Spanner\Query\Parameterizer as QueryParameterizer;
use Colopl\Spanner\Query\Processor as QueryProcessor;
use Colopl\Spanner\Schema\Builder as SchemaBuilder;
use Colopl\Spanner\Schema\Grammar as SchemaGrammar;
use DateTimeInterface;
use Exception;
use Generator;
use Google\Cloud\Core\Exception\AbortedException;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\QueryException;
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
        Concerns\ManagesTransactions,
        Concerns\ManagesStaleReads,
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
     * Try to maintain session pool on 'session not found' error
     */
    public const MAINTAIN_SESSION_POOL = 'MAINTAIN_SESSION_POOL';

    /**
     * Try to maintain and then clear session pool on 'session not found' error
     */
    public const CLEAR_SESSION_POOL = 'CLEAR_SESSION_POOL';

    /**
     * The QueryException is raised and the client code is free to handle it by itself
     */
    public const THROW_EXCEPTION = 'THROW_EXCEPTION';

    /**
     * Used to detect specific exception
     */
    public const SESSION_NOT_FOUND_CONDITION = 'Session does not exist';

    /**
     * @param string $instanceId instance ID
     * @param string $database
     * @param string $tablePrefix
     * @param array $config
     * @param CacheItemPoolInterface|null $authCache
     * @param SessionPoolInterface|null $sessionPool
     */
    public function __construct(string $instanceId, string $database, $tablePrefix = '', array $config = [], CacheItemPoolInterface $authCache = null, SessionPoolInterface $sessionPool = null)
    {
        $this->instanceId = $instanceId;
        $this->authCache = $authCache;
        $this->sessionPool = $sessionPool;
        parent::__construct(null, $database, $tablePrefix, $config);
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
        return (new QueryGrammar())->setConnection($this);
    }

    /**
     * @inheritDoc
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SchemaGrammar();
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
     * @inheritDoc
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

                $rowCount = $transaction->executeUpdate($query, ['parameters' => $this->prepareBindings($bindings)]);

                $this->recordsHaveBeenModified($rowCount > 0);

                return $rowCount;
            });
        };

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
     * @internal
     * @inheritDoc
     * @return void
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
            // We need to transform all instances of DateTimeInterface into the actual
            // date string. Each query grammar maintains its own date string format
            // so we'll just ask the grammar for the format to get from the date.
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            }
            else if ($value instanceof Arrayable) {
                $bindings[$key] = $value->toArray();
            }
        }

        return $bindings;
    }

    /**
     * @inheritDoc
     * @param scalar|list<mixed>|null $value
     */
    public function escape($value, $binary = false)
    {
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
                return !is_array($v)
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
            ? 'r"""' . addcslashes($value, '"') . '"""'
            : '"' . addcslashes($value, '"') . '"';
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
     * Returns current mode
     *
     * @return string
     */
    protected function getSessionNotFoundMode(): string
    {
        return $this->config['sessionNotFoundErrorMode'] ?? self::CLEAR_SESSION_POOL;
    }

    /**
     * Handle "session not found" errors
     *
     * @template T
     * @param  Closure(): T $callback
     * @return T
     * @throws AbortedException|NotFoundException|InvalidArgumentException
     */
    protected function withSessionNotFoundHandling(Closure $callback): mixed
    {
        $handlerMode = $this->getSessionNotFoundMode();
        if (!in_array($handlerMode, [
                self::MAINTAIN_SESSION_POOL,
                self::CLEAR_SESSION_POOL,
                self::THROW_EXCEPTION,
            ])
        ) {
            throw new InvalidArgumentException("Unsupported sessionNotFoundErrorMode [{$handlerMode}].");
        }

        if ($handlerMode === self::THROW_EXCEPTION
            || $this->sessionPool === null
            || $this->inTransaction()
        ) {
            // skip handlers, transaction() will handle error by self
            return $callback();
        }

        try {
            return $callback();
        } catch (Throwable $e) {
            // ensure if this really error with session
            if ($this->causedBySessionNotFound($e)) {
                $this->disconnect();
                // clear expired sessions, manually deleted sessions still raise error
                $this->maintainSessionPool();
                $this->reconnect();
                try {
                    return $callback();
                } catch (Throwable $e) {
                    if ($handlerMode === self::CLEAR_SESSION_POOL && $this->causedBySessionNotFound($e)) {
                        $this->disconnect();
                        // forcefully clearing sessions, might affect parallel processes
                        // also cleared sessions are still accounted toward spanner limit - 10k sessions per node
                        $this->clearSessionPool();
                        $this->reconnect();
                        return $callback();
                    } else {
                        throw $e;
                    }
                }
            } else {
                throw $e;
            }
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

        return $this->getDatabaseContext()
            ->execute($query, $options)
            ->rows();
    }

    /**
     * Check if this is "session not found" error
     *
     * @param Throwable $e
     * @return boolean
     */
    public function causedBySessionNotFound(Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            $e = $e->getPrevious();
        }

        return ($e instanceof NotFoundException)
            && str_contains($e->getMessage(), self::SESSION_NOT_FOUND_CONDITION);
    }

}
