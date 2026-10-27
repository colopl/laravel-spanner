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

namespace Colopl\Spanner\Tests\Schema;

use Closure;
use Colopl\Spanner\Schema\Blueprint;
use Colopl\Spanner\Tests\Queue\SpannerQueueIntegrationTest;
use Colopl\Spanner\Tests\Support\RecordingConnection;
use Colopl\Spanner\Tests\TestCase;

/**
 * These tests assert the generated DDL rather than executing it, so they run
 * wherever queues are unsupported as well. {@see SpannerQueueIntegrationTest}
 * is what sends the DDL to a backend.
 */
class QueueBlueprintTest extends TestCase
{
    /**
     * @param Closure(Blueprint): void $callback
     * @return list<string>
     */
    protected function compile(string $queue, Closure $callback): array
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, $queue);
        $callback($blueprint);

        return $blueprint->toSql();
    }

    public function test_createQueue(): void
    {
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->createQueue();
            $queue->string('MessageId', 36)->primary();
            $queue->string('Payload', 'max');
        });

        $this->assertSame([
            'create queue `Jobs` (`MessageId` string(36) not null, `Payload` string(max) not null) ' .
            'primary key (`MessageId`)',
        ], $sql);
    }

    public function test_createQueue_does_not_emit_alter_statements_for_its_columns(): void
    {
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->createQueue();
            $queue->string('MessageId', 36)->primary();
            $queue->string('Payload', 'max');
        });

        $this->assertCount(1, $sql);
        $this->assertStringNotContainsString('alter table', $sql[0]);
    }

    public function test_createQueue_with_bytes_payload_and_composite_key(): void
    {
        $sql = $this->compile('UserTasks', static function (Blueprint $queue) {
            $queue->createQueue();
            $queue->integer('UserId');
            $queue->string('MessageId', 36);
            $queue->binary('Payload');
            $queue->primary(['UserId', 'MessageId']);
        });

        $this->assertSame([
            'create queue `UserTasks` (`UserId` int64 not null, `MessageId` string(36) not null, ' .
            '`Payload` bytes(255) not null) primary key (`UserId`, `MessageId`)',
        ], $sql);
    }

    public function test_createQueue_interleaved_in_parent(): void
    {
        $sql = $this->compile('UserTasks', static function (Blueprint $queue) {
            $queue->createQueue();
            $queue->string('UserId', 36);
            $queue->string('MessageId', 36);
            $queue->string('Payload', 'max');
            $queue->primary(['UserId', 'MessageId']);
            $queue->interleaveInParent('User')->cascadeOnDelete();
        });

        $this->assertSame([
            'create queue `UserTasks` (`UserId` string(36) not null, `MessageId` string(36) not null, ' .
            '`Payload` string(max) not null) primary key (`UserId`, `MessageId`), ' .
            'interleave in parent `User` on delete cascade',
        ], $sql);
    }

    public function test_createQueue_with_row_deletion_policy(): void
    {
        // The policy can only use a column the queue declares itself, so the
        // implicit `DeliverTime` has to be declared, as part of the key.
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->createQueue();
            $queue->string('MessageId', 36);
            $queue->timestamp('DeliverTime');
            $queue->string('Payload', 'max');
            $queue->primary(['MessageId', 'DeliverTime']);
            $queue->deleteRowsOlderThan('DeliverTime', 7);
        });

        $this->assertSame([
            'create queue `Jobs` (`MessageId` string(36) not null, `DeliverTime` timestamp not null, ' .
            '`Payload` string(max) not null) primary key (`MessageId`, `DeliverTime`), ' .
            'row deletion policy (older_than(DeliverTime, interval 7 day))',
        ], $sql);
    }

    public function test_createQueue_with_options(): void
    {
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->createQueue()
                ->disableSend(true)
                ->disableDelivery(false)
                ->localityGroup('queues');
            $queue->string('MessageId', 36)->primary();
            $queue->string('Payload', 'max');
        });

        $this->assertSame([
            'create queue `Jobs` (`MessageId` string(36) not null, `Payload` string(max) not null) ' .
            "primary key (`MessageId`), options (disable_send=true, disable_delivery=false, locality_group='queues')",
        ], $sql);
    }

    public function test_createQueueIfNotExists(): void
    {
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->createQueueIfNotExists();
            $queue->string('MessageId', 36)->primary();
            $queue->string('Payload', 'max');
        });

        $this->assertSame([
            'create queue if not exists `Jobs` (`MessageId` string(36) not null, ' .
            '`Payload` string(max) not null) primary key (`MessageId`)',
        ], $sql);
    }

    public function test_alterQueue(): void
    {
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->alterQueue()->disableDelivery(true);
        });

        $this->assertSame(['alter queue `Jobs` set options (disable_delivery=true)'], $sql);
    }

    public function test_alterQueue_resets_an_option_with_null(): void
    {
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->alterQueue()->disableSend(null)->localityGroup(null);
        });

        $this->assertSame(
            ['alter queue `Jobs` set options (disable_send=null, locality_group=null)'],
            $sql,
        );
    }

    public function test_alterQueue_without_options_emits_nothing(): void
    {
        $this->assertSame([], $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->alterQueue();
        }));
    }

    public function test_dropQueue(): void
    {
        $this->assertSame(
            ['drop queue `Jobs`'],
            $this->compile('Jobs', static function (Blueprint $queue) {
                $queue->dropQueue();
            }),
        );
    }

    public function test_dropQueueIfExists(): void
    {
        $this->assertSame(
            ['drop queue if exists `Jobs`'],
            $this->compile('Jobs', static function (Blueprint $queue) {
                $queue->dropQueueIfExists();
            }),
        );
    }

    public function test_schema_builder_creates_a_queue(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();

        $connection->getSchemaBuilder()->createQueue('Jobs', static function (Blueprint $queue) {
            $queue->string('MessageId', 36)->primary();
            $queue->string('Payload', 'max');
        });

        $this->assertSame([
            'create queue `Jobs` (`MessageId` string(36) not null, `Payload` string(max) not null) ' .
            'primary key (`MessageId`)',
        ], $connection->recordedSql());
    }

    public function test_schema_builder_drops_a_queue(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();

        $connection->getSchemaBuilder()->dropQueueIfExists('Jobs');

        $this->assertSame(['drop queue if exists `Jobs`'], $connection->recordedSql());
    }

    public function test_schema_builder_lists_queues(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();
        $connection->selectResults = [
            ['name' => 'Jobs', 'schema' => '', 'parent' => null],
            ['name' => 'UserTasks', 'schema' => '', 'parent' => 'User'],
        ];

        $queues = $connection->getSchemaBuilder()->getQueues();

        $this->assertSame(['Jobs', 'UserTasks'], array_column($queues, 'name'));
        $this->assertSame(['Jobs', 'UserTasks'], array_column($queues, 'schema_qualified_name'));
        $this->assertSame([null, 'User'], array_column($queues, 'parent'));

        $sql = $connection->recordedAt(0)['sql'];
        $this->assertStringContainsString("where `table_type` = 'QUEUE'", $sql);
        $this->assertStringNotContainsString("'BASE TABLE'", $sql);
    }

    public function test_schema_builder_lists_the_queues_of_a_named_schema(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();
        $connection->selectResults = [['name' => 'Jobs', 'schema' => 'analytics', 'parent' => null]];

        $queues = $connection->getSchemaBuilder()->getQueues('analytics');

        $this->assertSame(['analytics.Jobs'], array_column($queues, 'schema_qualified_name'));
        $this->assertStringContainsString("and `table_schema` = 'analytics'", $connection->recordedAt(0)['sql']);
    }

    public function test_schema_builder_keeps_queues_out_of_the_table_listing(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();

        $connection->getSchemaBuilder()->getTables();

        $sql = $connection->recordedAt(0)['sql'];
        $this->assertStringContainsString("where `table_type` = 'BASE TABLE'", $sql);
        $this->assertStringNotContainsString("'QUEUE'", $sql);
    }

    public function test_schema_builder_tells_whether_a_queue_exists(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();
        $connection->selectResults = [['name' => 'Jobs', 'schema' => '', 'parent' => null]];
        $schema = $connection->getSchemaBuilder();

        $this->assertTrue($schema->hasQueue('Jobs'));
        $this->assertTrue($schema->hasQueue('jobs'), 'Spanner names are case insensitive.');
        $this->assertFalse($schema->hasQueue('Other'));
    }

    public function test_hasQueue_applies_the_table_prefix(): void
    {
        $connection = new RecordingConnection('app_');
        $connection->useDefaultSchemaGrammar();
        $connection->selectResults = [['name' => 'app_Jobs', 'schema' => '', 'parent' => null]];

        $this->assertTrue($connection->getSchemaBuilder()->hasQueue('Jobs'));
    }

    public function test_dropAllTables_drops_queues_before_tables(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();
        $connection->selectUsing = static fn(string $sql) => match (true) {
            str_contains($sql, "'QUEUE'") => [['name' => 'UserTasks', 'schema' => '', 'parent' => 'User']],
            str_contains($sql, "'BASE TABLE'") => [['name' => 'User', 'schema' => '', 'parent' => null]],
            default => [],
        };

        $connection->getSchemaBuilder()->dropAllTables();

        // A table cannot be dropped while a queue is interleaved in it.
        $this->assertSame(
            ['drop queue `UserTasks`', 'drop table `User`'],
            $this->dropStatements($connection),
        );
    }

    public function test_dropAllTables_drops_queues_when_there_are_no_tables(): void
    {
        $connection = new RecordingConnection();
        $connection->useDefaultSchemaGrammar();
        $connection->selectUsing = static fn(string $sql) => str_contains($sql, "'QUEUE'")
            ? [['name' => 'Jobs', 'schema' => '', 'parent' => null]]
            : [];

        $connection->getSchemaBuilder()->dropAllTables();

        $this->assertSame(['drop queue `Jobs`'], $this->dropStatements($connection));
    }

    /**
     * @return list<string>
     */
    protected function dropStatements(RecordingConnection $connection): array
    {
        return array_values(array_filter(
            $connection->recordedSql(),
            static fn(string $sql) => str_starts_with($sql, 'drop '),
        ));
    }
}
