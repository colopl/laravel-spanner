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

use BadMethodCallException;
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
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\QueryException;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

class Connection extends BaseConnection
{
    use Concerns\ManagesDataDefinitions,
        Concerns\ManagesLongRunningOperations,
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
     * @var QueryParameterizer
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
     * Create a new spanner database connection instance.
     *
     * @param string $instanceId instance ID
     * @param string $databaseName
     * @param string $tablePrefix
     * @param array $config
     * @param CacheItemPoolInterface $authCache
     * @param SessionPoolInterface $sessionPool
     * @throws Exception
     */
    public function __construct(string $instanceId, string $databaseName, $tablePrefix = '', array $config = [], CacheItemPoolInterface $authCache = null, SessionPoolInterface $sessionPool = null)
    {
        $this->instanceId = $instanceId;
        $this->authCache = $authCache;
        $this->sessionPool = $sessionPool;
        parent::__construct(null, $databaseName, $tablePrefix, $config);
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
     * @throws GoogleException
     */
    protected function getSpannerDatabase(): Database
    {
        $this->reconnectIfMissingConnection();
        return $this->spannerDatabase;
    }

    /**
     * @return Database|Transaction
     * @throws GoogleException
     */
    protected function getDatabaseContext()
    {
        return $this->inTransaction() ? $this->getCurrentTransaction() : $this->getSpannerDatabase();
    }

    /**
     * @return void
     * @throws GoogleException
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
     * @throws GoogleException
     */
    protected function reconnectIfMissingConnection()
    {
        if ($this->spannerDatabase === null) {
            $this->reconnect();
        }
    }

    /**
     * @return void
     */
    public function disconnect()
    {
        if ($this->spannerDatabase !== null) {
            $this->spannerDatabase->close();
            $this->spannerDatabase = null;
        }
    }

    /**
     * @return QueryGrammar
     */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return new QueryGrammar();
    }

    /**
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SchemaGrammar();
    }

    /**
     * @return SchemaBuilder|\Illuminate\Database\Schema\Builder
     */
    public function getSchemaBuilder()
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * @return QueryProcessor
     */
    protected function getDefaultPostProcessor(): QueryProcessor
    {
        return new QueryProcessor();
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param  string $table
     * @return QueryBuilder
     */
    public function table($table): QueryBuilder
    {
        return $this->query()->from($table);
    }

    /**
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * Run a select statement against the database.
     *
     * @param  string $query
     * @param  array  $bindings
     * @param  bool   $useReadPdo  Not used. This is here for compatibility reasons.
     * @return array
     * @throws Throwable
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $generator = $this->getDatabaseContext()
                ->execute($query, ['parameters' => $this->prepareBindings($bindings)])
                ->rows();

            return iterator_to_array($generator);
        });
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool   $useReadPdo  Not used. This is here for compatibility reasons.
     * @return Generator
     * @throws Throwable
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): Generator
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return call_user_func(function() { yield from []; });
            }

            return $this->getDatabaseContext()
                ->execute($query, ['parameters' => $this->prepareBindings($bindings)])
                ->rows();
        });
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param  string $query
     * @param  array $bindings
     * @return bool
     * @throws Throwable
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
        return $this->waitForOperation($this->runDdl($query)) !== null;
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param  string $query
     * @param  array $bindings
     * @return int
     * @throws Throwable
     */
    public function affectingStatement($query, $bindings = []): int
    {
        $body = function () use ($query, $bindings) {
            return $this->run($query, $bindings, function ($query, $bindings) {
                if ($this->pretending()) {
                    return 0;
                }

                $queryCall = function () use ($query, $bindings) {
                    return $this->getCurrentTransaction()->executeUpdate($query, ['parameters' => $this->prepareBindings($bindings)]);
                };

                $rowCount = $queryCall();

                $this->recordsHaveBeenModified($rowCount > 0);

                return $rowCount;
            });
        };

        if ($this->inTransaction()) {
            return $body();
        }

        // Create a temporary transaction for single affecting statement
        return $this->transaction($body);
    }

    /**
     * @param  string $query
     * @return bool
     * @throws Throwable
     */
    public function unprepared($query): bool
    {
        return $this->statement($query);
    }

    /**
     * @return string
     * @throws GoogleException
     */
    public function getDatabaseName()
    {
        return $this->getSpannerDatabase()->name();
    }

    /**
     * @param string $database
     * @return string|void
     * @throws BadMethodCallException
     * @internal
     */
    public function setDatabaseName($database)
    {
        $this->markAsNotSupported('setDatabaseName');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     * @internal
     */
    public function getPdo()
    {
        $this->markAsNotSupported('PDO access');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     * @internal
     */
    public function getReadPdo()
    {
        $this->markAsNotSupported('PDO access');
    }

    /**
     * @return void
     * @throws BadMethodCallException
     * @internal
     */
    public function getDoctrineConnection()
    {
        $this->markAsNotSupported('Doctrine');
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param  array  $bindings
     * @return array
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
        }

        return $bindings;
    }

    /**
     * @param  string    $query
     * @param  array     $bindings
     * @param  Closure  $callback
     * @return mixed
     * @throws AbortedException|QueryException
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        $this->parameterizer = $this->parameterizer ?? new QueryParameterizer();
        [$query, $bindings] = $this->parameterizer->parameterizeQuery($query, $bindings);

        try {
            $result = $callback($query, $bindings);
        }

        // AbortedExceptions are expected to be thrown upastream by the Google Client Library upstream,
        // so AbortedExceptions will not be wrapped with QueryException.
        catch (AbortedException $e) {
            throw $e;
        }

        // If an exception occurs when attempting to run a query, we'll format the error
        // message to include the bindings with SQL, which will make this exception a
        // lot more helpful to the developer instead of just the database's errors.
        catch (Exception $e) {
            throw new QueryException($query, $this->prepareBindings($bindings), $e);
        }

        return $result;
    }
}
