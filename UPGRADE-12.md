## Upgrading to v12.0.0

In this version, the underlying `google/cloud-spanner` library has been upgraded from `^1.106` to `^2.10`.
If you pass options through `client`, also follow the upstream [v1-to-v2 migration guide](https://github.com/googleapis/google-cloud-php-spanner/blob/v2.10.5/MIGRATING.md), which documents the client options that moved or were removed.
This upgrade introduces some breaking changes. The biggest change is in how the sessions are managed. 
In v1 the sessions were handled by the session pool, and every process checked out sessions from the pool as needed. 
In v2, the session pool is no longer used and all processes run on a single multiplexed session. 
This means that the session is now shared across all processes, and the session pool is no longer used.

### Upgrade steps

1. Remove the `session_pool` option from every spanner connection in `config/database.php`. (`minSessions`, `maxSessions`, etc.) no longer exists.
2. Commands `spanner:cooldown` / `spanner:sessions` has been removed. Remove any references to them in your codebase.
3. Drop the `--refresh` option from any `spanner:warmup` invocation. It now refreshes the single (multiplexed) session instead of filling a pool.
4. Delete the old session cache files (by default `storage/framework/spanner/{connection}_sessions`),
   since the cached entries are no longer compatible with v2.
5. Replace any usage of the removed APIs listed below.

### Removed classes

- `Colopl\Spanner\Concerns\ManagesSessionPool` → replaced by `Colopl\Spanner\Concerns\ManagesSession`
- `Colopl\Spanner\Session\SessionInfo`
- `Colopl\Spanner\Console\SessionsCommand`
- `Colopl\Spanner\Console\CooldownCommand`

### Edited Classes

#### `Colopl\Spanner\Connection`

- 6th constructor argument: `?SessionPoolInterface $sessionPool` → `?CacheItemPoolInterface $sessionCache`
- Removed: `clearSessionPool()`, `maintainSessionPool()`, `warmupSessionPool()`, `listSessions()`, `__debugInfo()`
- Added: `refreshSession()`, `getSessionName()`
- `disconnect()` no longer closes the underlying `Database` (there is no session to return to a pool).

#### `Colopl\Spanner\SpannerServiceProvider`

- `createSessionPool(): SessionPoolInterface` → `createSessionCache(): AdapterInterface`
- `boot()` has been removed. Connections are no longer disconnected after each queue job,
  since sessions are no longer checked out from a pool.

#### Snapshots and timestamp bounds

- `Colopl\Spanner\TimestampBound\*` now uses `Google\Protobuf\Duration` instead of
  `Google\Cloud\Spanner\Duration`. Pass an `int` (seconds) or a `Google\Protobuf\Duration`.
- `ExactStaleness::$duration`, `MaxStaleness::$duration`, and `ReadTimestamp::$timestamp` are now
  strictly typed properties, and the constructors declare their parameter types.
- `ManagesSnapshots::$currentSnapshot` changed from `Google\Cloud\Spanner\Snapshot` to
  `Google\Cloud\Spanner\TransactionalReadInterface`.

#### Query options

- Query level request timeouts are now sent as `timeoutMillis` (integer milliseconds) instead of
  `requestTimeout` (seconds). `Query\Builder::setRequestTimeoutSeconds()` still takes seconds, but a value
  resolving to less than 1ms now throws a `LogicException`.
- Transaction retries moved from `['maxRetries' => n]` to `['retrySettings' => ['maxRetries' => n]]`.
- Data boost (partitioned) queries now throw a `LogicException` when given options that are not
  supported by the partitioned query APIs, instead of silently ignoring them.
