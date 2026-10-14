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
use Colopl\Spanner\Tests\Support\RecordingConnection;
use Colopl\Spanner\Tests\TestCase;

/**
 * Cloud Spanner queues are not implemented by the Spanner emulator, so these
 * tests assert the generated DDL rather than executing it.
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
        $sql = $this->compile('Jobs', static function (Blueprint $queue) {
            $queue->createQueue();
            $queue->string('MessageId', 36)->primary();
            $queue->string('Payload', 'max');
            $queue->deleteRowsOlderThan('DeliverTime', 7);
        });

        $this->assertSame([
            'create queue `Jobs` (`MessageId` string(36) not null, `Payload` string(max) not null) ' .
            'primary key (`MessageId`), row deletion policy (older_than(DeliverTime, interval 7 day))',
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
}
