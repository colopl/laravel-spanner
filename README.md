laravel-spanner
================

Laravel database driver for Google Cloud Spanner

[![License](https://img.shields.io/packagist/l/colopl/laravel-spanner.svg?style=flat-square)](https://github.com/colopl/laravel-spanner/blob/master/LICENSE)
[![Latest Stable Version](https://img.shields.io/packagist/v/colopl/laravel-spanner.svg?style=flat-square)](https://packagist.org/packages/colopl/laravel-spanner)
[![Minimum PHP Version](https://img.shields.io/packagist/php-v/colopl/laravel-spanner.svg?style=flat-square)](https://secure.php.net/)

## Requirements

- PHP >= 8.3
- Laravel >= 13
- [gRPC extension](https://cloud.google.com/php/grpc)
- [protobuf extension](https://cloud.google.com/php/grpc#install_the_protobuf_runtime_library) (recommended for better performance)
- `sysvmsg`, `sysvsem`, `sysvshm` extensions (recommended for better performance)

## Installation
Put JSON credential file path to env variable: `GOOGLE_APPLICATION_CREDENTIALS`

```
export GOOGLE_APPLICATION_CREDENTIALS=/path/to/key.json
```

Install via composer

```sh
composer require colopl/laravel-spanner
```

Add connection config to `config/database.php`

```php
[
    'connections' => [
        'spanner' => [
            'driver' => 'spanner',
            'instance' => '<Cloud Spanner instanceId here>',
            'database' => '<Cloud Spanner database name here>',
        ]
    ]
];
```

That's all. You can use database connection as usual.

```php
$conn = DB::connection('spanner');
$conn->...
```

## Additional Configurations
You can pass `SpannerClient` config options as below.
For more information, please see [Google Client Library docs](http://googleapis.github.io/google-cloud-php/#/docs/google-cloud/latest/spanner/spannerclient?method=__construct)

```php
[
    'connections' => [
        'spanner' => [
            'driver' => 'spanner',
            'instance' => '<Cloud Spanner instanceId here>',
            'database' => '<Cloud Spanner database name here>',
            
            // Spanner Client configurations
            'client' => [
                'projectId' => 'xxx',
                'requestTimeout' => 60, // seconds
                ...
            ],
            
            // Isolation level for transactions ('SERIALIZABLE' | 'REPEATABLE READ' | null (default))
            'isolation_level' => 'SERIALIZABLE',
        ]
    ]
];
```

## Recommended Setup

Please note that the following are not required, but are strongly recommended for better performance.

- Install `protobuf` pecl extension for faster network communication.
- Install `sysvmsg`, `sysvsem`, `sysvshm` extensions for faster session management.
- Mount the cache directory (`./storage/framework/spanner` by default) to tmpfs for better session io performance. 
  Cache path can be changed by setting `connections.{name}.cache_path` in your `config/database.php` file.

## Unsupported features

- STRUCT data types
- Inserting/Updating JSON data types

## Limitations

### SQL Mode
Currently only supports Spanner running GoogleSQL (PostgreSQL mode is not supported).

### Query
- [Binding more than 950 parameters in a single query will result in an error](https://cloud.google.com/spanner/quotas#query-limits)
  by the server. In order to by-pass this limitation, this driver will attempt to switch to using `Query\Builder::whereInUnnest(...)`
  internally when the passed parameter exceeds the limit set by `parameter_unnest_threshold` config (default: `900`).
  You can turn this feature off by setting the value to `false`.

### Eloquent
If you use interleaved keys, you MUST define them in the `interleaveKeys` property, or else you won't be able to save. 
For more detailed instructions, see `Colopl\Spanner\Tests\Eloquent\ModelTest`.

## Additional Information

### Migrations

Since Spanner recommends using UUID as a primary key, `Blueprint::increments` (and all of its variants) will create a 
column of type `STRING(36) DEFAULT (GENERATE_UUID())` to generate and fill the column with a UUID
and flag it as a primary key. If you want to use `AUTO_INCREMENT`, you can do so by specifying it directly like this:

```php
// `default_sequence_kind` must be set in order to use auto increment
$schemaBuilder->setDatabaseOptions([
    'default_sequence_kind' => 'bit_reversed_positive',
]);

$schemaBuilder->create('user', function (Blueprint $table) {
    $table->integer('id')->primary()->autoIncrement();
});
```

### Transactions
Google Cloud Spanner sometimes requests transaction retries (e.g. `UNAVAILABLE`, and `ABORTED`), even if the logic is correct. For that reason, please do not manage transactions manually.

You should always use the `transaction` method which handles retry requests internally.

```php
// BAD: Do not use transactions manually!!
try {
    DB::beginTransaction();
    ...
    DB::commit();
} catch (\Throwable $ex) {
    DB::rollBack();
}

// GOOD: You should always use transaction method
DB::transaction(function() {
    ...
});
```

Google Cloud Spanner creates transactions for all data operations even if you do not explicitly create transactions.

In particular, in the SELECT statement, the type of transaction varies depending on whether it is explicit or implicit.

```php
// implicit transaction (Read-only transaction)
$conn->select('SELECT ...');

// explicit transaction (Read-write transaction)
$conn->transaction(function() {
    $conn->select('SELECT ...');
});

// implicit transaction (Read-write transaction)
$conn->insert('INSERT ...');

// explicit transaction (Read-write transaction)
$conn->transaction(function() {
    $conn->insert('INSERT ...');
});
```

| Transaction type | **SELECT** statement | **INSERT/UPDATE/DELETE** statement |
| :--- | :--- | :--- |
| implicit transaction | **Read-only** transaction with **singleUse** option | **Read-write** transaction with **singleUse** option |
| explicit transaction | **Read-write** transaction | **Read-write** transaction |

For more information, see [Cloud Spanner Documentation about transactions](https://cloud.google.com/spanner/docs/transactions)

### Stale reads

You can use [Stale reads (timestamp bounds)](https://cloud.google.com/spanner/docs/timestamp-bounds) as below.

```php
// There are four types of timestamp bounds: ExactStaleness, MaxStaleness, MinReadTimestamp and ReadTimestamp.
$timestampBound = new ExactStaleness(10);

// by Connection
$connection->selectWithTimestampBound('SELECT ...', $bindings, $timestampBound);

// by Query Builder
$queryBuilder
    ->withStaleness($timestampBound)
    ->get();
```

Stale reads always runs as read-only transaction with `singleUse` option. So you can not run as read-write transaction.

### Snapshot reads

You can use explicit Snapshot reads, either on `Connection`, or on `Model` or `Builder` instances. When running `snapshot()` on `Connection`, you pass a `Closure` that you can use to run multiple reads from within the same Snapshot.

```php
$timestampBound = new ExactStaleness(10);

// by Connection
$connection->snapshot($timestampBound, function() use ($connection) {
    $result1 = $connection->table('foo')->get();
    $result2 = $connection->table('bar')->get();

    return [$result1, $result2];
);

// by Model
User::where('foo', 'bar')
    ->snapshot($timestampBound)
    ->get();

// by Query Builder
$queryBuilder
    ->snapshot($timestampBound)
    ->get();
```

### Data Boost

Data boost creates snapshot and runs the query in parallel without affecting existing workloads.

You can read more about it [here](https://cloud.google.com/spanner/docs/databoost/databoost-overview).

Below are some examples of how to use it.

```php
// Using Connection
$connection->selectWithOptions('SELECT ...', $bindings, ['dataBoostEnabled' => true]);

// Using Query Builder
$queryBuilder
    ->useDataBoost()
    ->setRequestTimeoutSeconds(60)
    ->get();
```

> [!NOTE]
> This creates a new snapshot request which does not share state with any ongoing transactions.
> This means, queries running with data boost will not take into account any changes made in the ongoing transaction.

### Request Tags and Transaction Tags

Spanner allows you to attach tags to your queries and transactions that can be [used for troubleshooting](https://cloud.google.com/spanner/docs/introspection/troubleshooting-with-tags).

You can set request tags and transaction tags as below.

```php
$requestPath = request()->path();
$tag = 'url=' . $requestPath;
$connection->setRequestTag($tag);
$connection->setTransactionTag($tag);
```

### Data Types

Some data types of Google Cloud Spanner does not have corresponding built-in type of PHP.
You can use following classes by [Google Cloud PHP Client](https://github.com/googleapis/google-cloud-php)

- BYTES: `Google\Cloud\Spanner\Bytes`
- DATE: `Google\Cloud\Spanner\Date`
- NUMERIC: `Google\Cloud\Spanner\Numeric`
- TIMESTAMP: `Google\Cloud\Spanner\Timestamp`

When fetching rows, the library coverts the following column types
- `Timestamp` -> [Carbon](https://laravel.com/api/10.x/Illuminate/Support/Carbon.html) with the default timezone in PHP
- `Numeric` -> `string`

Note that if you execute a query without QueryBuilder, it will not have these conversions.


### Partitioned DML
You can run partitioned DML as below.

```php
// by Connection
$connection->runPartitionedDml('UPDATE ...');


// by Query Builder
$queryBuilder->partitionedUpdate($values);
$queryBuilder->partitionedDelete();
```

However, Partitioned DML has some limitations. See [Cloud Spanner Documentation about Partitioned DML](https://cloud.google.com/spanner/docs/dml-partitioned#dml_and_partitioned_dml) for more information.


### Interleave
You can define [interleaved tables](https://cloud.google.com/spanner/docs/schema-and-data-model#creating_a_hierarchy_of_interleaved_tables) as below.

```php
$schemaBuilder->create('user_items', function (Blueprint $table) {
    $table->uuid('user_id');
    $table->uuid('id');
    $table->uuid('item_id');
    $table->integer('count');
    $table->timestamps();

    $table->primary(['user_id', 'id']);
    
    // interleaved table
    $table->interleaveInParent('users')->cascadeOnDelete();
    
    // interleaved index
    $table->index(['userId', 'created_at'])->interleaveIn('users');
});
```

### Row Deletion Policy

You can define [row deletion policy](https://cloud.google.com/spanner/docs/ttl/working-with-ttl) as below.

```php
$schemaBuilder->create('user', function (Blueprint $table) {
    $table->uuid('user_id');
    $table->timestamps();
    
    // create a policy
    $table->deleteRowsOlderThan(['updated_at'], 365);
});

$schemaBuilder->table('user', function (Blueprint $table) {
    // add policy
    $table->addRowDeletionPolicy('udpated_at', 100);

    // replace policy
    $table->replaceRowDeletionPolicy('udpated_at', 100);

    // drop policy
    $table->dropRowDeletionPolicy();
});
```

### Sequence

If you want a simple sequence to be used as a primary key, you can use `useSequence()` method.
If `useSequence()` is called without providing a `$name`, a sequence with name `user_id_sequence` will be created
with `start_with_counter` set with a random value between 1 and 1,000,000.

```php
$schemaBuilder->create('user', function (Blueprint $table) {
    $table->integer('id')->useSequence();
});
```

If you want more flexibility, you can also create, alter, and drop sequences directly as below.

```php
$schemaBuilder->create('user_items', function (Blueprint $table) {
    $table->createSequence('sequence_name');
    $table->integer('id')->useSequence('sequence_name');
    
    $table->alterSequence('sequence_name')
        ->startWithCounter(100)
        ->skipRangeMin(1)
        ->skipRangeMax(10);
    
    $table->dropSequence('sequence_name');
});
```

### Change Streams

Spanner supports [Change Streams](https://cloud.google.com/spanner/docs/change-streams) which allows you to listen to changes in the database.
Change streams can be created/altered/dropped through the schema builder as shown below.

```php
$schemaBuilder->create('user_items', function (Blueprint $table) {
    $table->createChangeStream('stream_name')
        ->for('user_items', ['userId', 'userItemId'])
        ->retentionPeriod('7d')
        ->valueCaptureType(ChangeStreamValueCaptureType::NewValues)
        ->excludeTtlDeletes(true);

    $table->createChangeStream('stream_name')
        ->excludeInsert(true)
        ->excludeUpdate(true)
        ->excludeDelete(true);
    
    $table->dropChangeStream('stream_name');
});
```

### Full Text Search

Spanner supports [Full Text Search](https://cloud.google.com/spanner/docs/full-text-search) which allows you to search for text in columns.

You can define a token list column and a search index for the column as below.

```php
$schemaBuilder->create('user', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    // adds an invisible column for full text search
    $table->tokenList('UserNameTokens', TokenizerFunction::FullText, 'name', ['language_tag' => 'en']);
    
    // adds a SEARCH INDEX
    $table->fullText(['UserNameTokens']);
});
```

Once the schema has been applied, you can use the search methods in the query builder to search for text in the columns as below.

```php
User::query()->searchFullText('UserNameTokens', 'John OR Kevin', ['enhance_query' => true])->get();
```

The methods available are `searchFullText`, `searchSubstring`, and `searchNgrams`.

### Secondary Index Options

You can define Spanner specific index options like [null filtering](https://cloud.google.com/spanner/docs/secondary-indexes#null-indexing-disable) and [storing](https://cloud.google.com/spanner/docs/secondary-indexes#storing-clause) as below.

```php
$schemaBuilder->table('user_items', function (Blueprint $table) {
    $table->index('userId')
        // Interleave in parent table
        ->interleaveIn('user')
        // Add null filtering
        ->nullFiltered()
        // Add storing
        ->storing(['itemId', 'count']);
});
```

### Queues

Spanner supports [Queues](https://cloud.google.com/spanner/docs/queues/queues-overview), a transactional
messaging feature that lets you send and acknowledge messages atomically together with your other writes.
This package ships a Laravel queue driver built on top of it.

> [!IMPORTANT]
> Spanner queues require the Enterprise or Enterprise Plus edition. The Spanner emulator supports them from
> version 1.5.59, with a few differences described in [Using the emulator](#using-the-emulator).
> An instance is limited to 100 queues.

#### Creating a queue

Every Laravel queue name maps to one Spanner `QUEUE` object, which is created through the schema builder.
The queue must have a `Payload` column, and every other column has to be part of the primary key.
The driver expects a `MessageId` primary key and a `Payload` column holding the serialized job:

```php
$schemaBuilder->createQueue('Jobs', function (Blueprint $queue) {
    $queue->string('MessageId', 36)->primary();
    $queue->string('Payload', 'max');
});
```

`Payload` has to be a `STRING` column. The driver writes the job's JSON as a string, so a `BYTES` payload, as
`$queue->binary('Payload')` would create, is rejected by Spanner on the first push with *"Value has type STRING
which cannot be inserted into column Payload, which has type BYTES"*.

Spanner adds a `DeliverTime` column to every queue implicitly, so you don't declare it.

Queues can also be interleaved into a table. Interleaving requires the queue's primary key to be prefixed by
the parent's key columns, positionally matching in name and type, so the queue gains key columns in front of
`MessageId` (see [Interleaved queues](#interleaved-queues) for what the driver needs in order to write them):

```php
$schemaBuilder->createQueue('UserTasks', function (Blueprint $queue) {
    $queue->string('UserId', 36);
    $queue->string('MessageId', 36);
    $queue->string('Payload', 'max');
    $queue->primary(['UserId', 'MessageId']);
    $queue->interleaveInParent('User')->cascadeOnDelete();
});

$schemaBuilder->dropQueue('UserTasks');
```

`interleaveInParent()` requires the parent row to exist for every message. `interleaveIn()` is part of the
documented grammar as well, but the emulator rejects it for queues, so it has not been verified.

#### Expiring stale messages

A [Row Deletion Policy](#row-deletion-policy) can remove messages that nothing has picked up. The policy has to
use a column that the queue declares itself, so the implicit `DeliverTime` has to be declared explicitly, as
part of the primary key:

```php
$schemaBuilder->createQueue('Jobs', function (Blueprint $queue) {
    $queue->string('MessageId', 36);
    $queue->timestamp('DeliverTime');
    $queue->string('Payload', 'max');
    $queue->primary(['MessageId', 'DeliverTime']);
    $queue->deleteRowsOlderThan('DeliverTime', 7);
});
```

A message gets a fresh `DeliverTime` each time a job is reserved or released, so a job that keeps being
retried does not expire. Only a message that has gone unhandled for the given number of days does.

Queue options are set on the `QueueDefinition` that the callback receives as its second argument.
`disableSend` rejects new messages, which is useful to drain a queue before dropping it, and
`disableDelivery` pauses delivery to consumers:

```php
use Colopl\Spanner\Schema\QueueDefinition;

$schemaBuilder->createQueue('Jobs', function (Blueprint $queue, QueueDefinition $definition) {
    // Collect messages without delivering them yet.
    $definition->disableDelivery(true);

    $queue->string('MessageId', 36)->primary();
    $queue->string('Payload', 'max');
});
```

Don't call `$queue->createQueue()` yourself in that callback, since the schema builder already did. A second
call would declare a second queue.

Options can be changed afterwards with `alterQueue()`:

```php
$schemaBuilder->table('Jobs', function (Blueprint $queue) {
    $queue->alterQueue()->disableDelivery(true);
});

// Passing null resets an option back to its default.
$schemaBuilder->table('Jobs', function (Blueprint $queue) {
    $queue->alterQueue()->disableDelivery(null);
});
```

Queues are not tables, so `hasTable()` and `getTables()` never report them. Use `hasQueue()` and `getQueues()`
instead, the latter also telling which table a queue is interleaved in:

```php
$schemaBuilder->hasQueue('Jobs'); // true

foreach ($schemaBuilder->getQueues() as $queue) {
    echo $queue['name'], ' => ', $queue['parent'] ?? '-', PHP_EOL;
}
```

`dropAllTables()`, and with it `db:wipe` and `migrate:fresh`, drops queues too. Queues go first, since a table
can't be dropped while a queue is interleaved in it.

#### Configuration

Add a `spanner` queue connection to `config/queue.php`:

```php
[
    'connections' => [
        'spanner' => [
            'driver' => 'spanner',
            // Database connection from config/database.php. Defaults to the default connection.
            'connection' => 'spanner',
            // Name of the Spanner queue, as created above.
            'queue' => 'Jobs',
            // Seconds a reserved job stays invisible before it is delivered again.
            'retry_after' => 90,
            // Seconds a single receiving call waits for messages.
            'block_for' => 20,
            'after_commit' => false,
            // Key columns in front of MessageId, by queue name. See "Interleaved queues".
            'interleave_keys' => [
                'Jobs' => [],
            ],
        ],
    ],
];
```

`retry_after` works like it does for the `database` and `redis` drivers and must be longer than your longest
job, otherwise the job will be processed twice. A job that declares a `$timeout` is covered without raising it
for every job: its reserved message stays invisible for the `$timeout` plus 10 seconds when that is longer than
`retry_after`. Jobs without a `$timeout` rely on `retry_after` alone, and `queue:work --timeout` is not visible
to the driver, so keep `retry_after` above it.

`block_for` is the `max_duration` of the `RECEIVE_<queue>()` call. Receiving is a long running streaming
query rather than a poll, so a worker blocks inside it until a message arrives or the duration elapses.
A longer duration means fewer requests, but a worker can only react to `queue:restart` and to signals such
as `SIGTERM` once the current call returns, so it also delays graceful shutdown by up to that long.

> [!WARNING]
> As with the `redis` driver's `block_for`, avoid giving one worker several queues
> (`queue:work spanner --queue=High,Low`) while `block_for` is long. Queues are read in order, so an empty
> `High` makes the worker wait `block_for` seconds before it even looks at `Low`.

#### Interleaved queues

A queue interleaved into a table is keyed by that table's key columns followed by `MessageId`, so the driver
has to know what to write into them. These are the same interleave keys an Eloquent model declares in its
[`$interleaveKeys` property](#eloquent). List them in `interleave_keys` under the name of the queue, in primary
key order. Every queue has its own entry, so one connection can serve queues of different shapes:

```php
'spanner' => [
    'driver' => 'spanner',
    'queue' => 'Jobs',
    'interleave_keys' => [
        'UserTasks' => ['UserId'],
        'Jobs' => [],
    ],
],
```

Every queue you use needs an entry: a list of the key columns, or `[]` for a queue that is not interleaved.
The driver cannot tell a queue without interleave keys from one you forgot to declare, so a queue without an
entry throws an `InvalidArgumentException` naming it, the first time it is pushed to or popped from.

The values are then worked out automatically. A dispatched job object is serialized into an opaque blob
inside the payload, so the driver inspects the job itself before the payload is built, and falls back to the
payload for jobs pushed as a class name. A job implementing `Colopl\Spanner\Queue\ProvidesInterleaveKeys` is
asked directly; otherwise each column is looked for as a public property of the job, then under the payload's
`data`. Both the column's own spelling and its `lcfirst` form are tried, so `UserId` matches `$userId` too.

So this needs no extra code at all:

```php
class ProcessUserTask implements ShouldQueue
{
    public function __construct(public string $userId) {}
}

// or
Queue::push('ProcessUserTask', ['userId' => $userId]);
```

Implement `ProvidesInterleaveKeys` when the value has to be computed, which is the usual case once a job
holds a model rather than an id:

```php
class ProcessUserTask implements ShouldQueue, ProvidesInterleaveKeys
{
    public function __construct(private User $user) {}

    public function getInterleaveKeys(): array
    {
        return ['UserId' => $this->user->getKey()];
    }
}
```

`pushRaw()` can pass the values directly, which is useful when there is no payload to derive them from:

```php
$queue->pushRaw($payload, 'UserTasks', ['keys' => ['UserId' => $userId]]);
```

If a column cannot be resolved, sending fails with an error naming the column and every way to supply it.

Only newly sent messages go through this. Reserving and releasing a job carry the key values of the message
they came from, so a job never moves to a different parent row.

With `interleaveInParent()` the parent row must already exist when the message is sent, so send it in the
same transaction that creates the parent.

#### Dispatching jobs transactionally

Because messages are rows, dispatching a job inside a transaction makes it part of that transaction. The job
becomes deliverable only if the transaction commits, without needing the `afterCommit` dance:

```php
DB::connection('spanner')->transaction(function () use ($user) {
    $user->save();
    // Never delivered if the transaction is rolled back.
    ProcessSignup::dispatch($user);
});
```

#### How a job is dequeued and retried

**Dequeuing.** Each worker keeps one `RECEIVE_<queue>(max_duration => block_for)` stream open per queue and
reads messages from it as `queue:work` asks for them, instead of polling. A stream is a single-use strong
read, which is why messages cannot be received from inside a transaction. When a stream has reached its
`max_duration` it is replaced rather than reused.

Spanner hands out a message with a fixed, non-configurable 10 second lease, which is too short for most
Laravel jobs, and PHP cannot renew it from a background thread while a job is running. The driver therefore
uses the pattern Google documents for
[long running work](https://cloud.google.com/spanner/docs/queues/queues-examples#handle-long-running-work),
and reserves every message it receives:

1. The attempt counter in the payload (`attempts`, which `$job->attempts()` returns) is incremented.
2. In one read-write transaction, the received message is acknowledged (deleted by its primary key) and a
   fresh message with a new `MessageId` and the incremented payload is sent, scheduled `retry_after` seconds
   ahead (or the job's `$timeout` plus 10 seconds, if that is longer). An interleaved queue keeps the key columns of the message it replaces.
3. If the acknowledging `DELETE` removes no row, another worker reserved the message first and it is skipped.
   This also covers a message whose 10 second lease ran out and was delivered to a second worker: only one of
   them can delete it, so no locking is needed, and the worker's clock is never compared with Spanner's.
4. The worker runs the job against the reserved message.

A message whose payload is not a JSON object, for example one sent by another service, cannot be run by any
worker and would otherwise be redelivered forever. It is deleted instead of reserved, and `pop()` then throws
`Colopl\Spanner\Queue\UndecodablePayloadException`. The worker reports it to the exception handler and moves
on, and the exception's `context()` puts the full payload in the log entry, since the log is the only trace of
it that remains.

**What happens next** depends on how the job ends:

| Outcome | What the driver does |
|---|---|
| The job succeeds | The reserved message is acknowledged, so the job is gone. |
| The job throws and has tries left | Laravel calls `release($backoff)`. In one transaction the reserved message is acknowledged and the payload is sent again, deliverable after `$backoff` seconds (immediately without a backoff). |
| The job exceeds `--tries` or `--max-exceptions`, or calls `fail()` | Laravel records it in the failed jobs provider and the reserved message is acknowledged. |
| The worker is killed, crashes or times out | Nothing is released. Once `retry_after` seconds have passed the reserved message becomes deliverable and the next worker receives and reserves it again. |

Because every delivery goes through reservation, the attempt counter also counts deliveries after a crash,
so a job that kills its worker cannot retry forever. `--tries` and `--backoff` behave as they do with the
`database` and `redis` drivers, since the retry decisions are made by Laravel's worker, not by the driver.

If a job runs longer than its reservation (`retry_after`, or the longer `$timeout` window), its reserved message becomes deliverable while it is still running
and another worker may start the same job. When the first worker finishes, its acknowledgement finds no row
and does nothing. Keep `retry_after` above your longest job, or give long jobs a `$timeout`.

Two more consequences are worth knowing:

- Delivery is at-least-once, as it is for every Laravel queue driver, so jobs should be idempotent.
- A reserved job is stored as a message that is not deliverable yet, which Spanner cannot tell apart from a
  delayed one. `Queue::delayedSize()` therefore counts in-flight jobs as well, and `Queue::reservedSize()`
  always returns `0`.

#### Using the emulator

The emulator can run queues, but it differs from Cloud Spanner in ways that matter to a worker:

- It releases a stream's rows when its `max_duration` ends instead of pushing them as they arrive, so every
  receiving call lasts the whole `block_for`, even with messages waiting. Set `block_for` to `1` or `2` for
  local development and for tests.
- It can hand one message to several workers at once, which Cloud Spanner does not do while the message is
  leased. The driver copes: exactly one worker's acknowledgement succeeds and the others skip the message.
- It accepts `disable_send`, `disable_delivery` and `locality_group` without enforcing them, and rejects
  `INTERLEAVE IN` for queues.

#### Other notes

- Keep payloads under 4 KB, as Google recommends. Use the
  [out-of-band storage pattern](https://cloud.google.com/spanner/docs/queues/queues-examples#handle-large-message-payloads)
  for bigger data.
- A queue name is interpolated into the `RECEIVE_<queue>()` function name, where it cannot be bound as a
  parameter, so it must be a plain Spanner identifier.
- Each worker keeps one receiving stream open per queue, as Spanner expects. A queue allows at most 1000
  concurrent receivers.
- `Queue::clear()` deletes every message in a single transaction, so it is subject to the transaction
  mutation limit and is not suited to very large queues.

### Mutations

You can [insert, update, and delete data using mutations](https://cloud.google.com/spanner/docs/modify-mutation-api) to modify data instead of using DML to improve performance.

```
$queryBuilder->insertUsingMutation($values);
$queryBuilder->updateUsingMutation($values);
$queryBuilder->insertOrUpdateUsingMutation($values);
$queryBuilder->deleteUsingMutation($values);
```

Please note that mutation api does not work the same way as DML.
All mutations calls within a transaction are queued and sent as batch at the time you commit.
This means that if you make any modifications through the above functions and then try to SELECT the same records before committing, the returned results will not include any of the modifications you've made inside the transaction.


### SessionCache and AuthCache

In order to improve the performance of the first connection per request, we use [AuthCache](https://github.com/googleapis/google-cloud-php#caching-access-tokens) and [SessionCache](https://docs.cloud.google.com/spanner/docs/sessions#multiplexed_sessions).

By default, this library uses [Filesystem Cache Adapter](https://symfony.com/doc/current/components/cache/adapters/filesystem_adapter.html) for caching sessions. 
If you want to use a different adapter, you can extend ServiceProvider and inject it into the constructor of `Colopl\Spanner\Connection`.

Session initialization takes about a second, so warming up the session during the boot up phase of your
server is recommended. This can be achieved by running the `php artisan spanner:warmup` command.

### Laravel Tinker
You can use [Laravel Tinker](https://github.com/laravel/tinker) with commands such as `php artisan tinker`.
But your session may hang when accessing Cloud Spanner. This is known gRPC issue that occurs when PHP forks a process.
The workaround is to add following line to `php.ini`.

```ini
grpc.enable_fork_support=1
```



## Development

### Testing
You can run tests on docker by the following command. Note that some environment variables must be set.
In order to set the variables, rename [.env.sample](./.env.sample) to `.env` and edit the values of the
defined variables.

| Name | Value |
| :-- | :--   |
| `GOOGLE_APPLICATION_CREDENTIALS` | The path of the service account key file with access privilege to Google Cloud Spanner instance |
| `DB_SPANNER_INSTANCE_ID` | Instance ID of your Google Cloud Spanner |
| `DB_SPANNER_DATABASE_ID` | Name of the database with in the Google Cloud Spanner instance |
| `DB_SPANNER_PROJECT_ID` | Not required if your credential includes the project ID  |

```sh
make test
```

## License
Apache 2.0 - See [LICENSE](./LICENSE) for more information.
