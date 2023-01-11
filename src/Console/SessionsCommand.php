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

namespace Colopl\Spanner\Console;

use Colopl\Spanner\Connection as SpannerConnection;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

class SessionsCommand extends Command
{
    protected $signature = 'spanner:sessions {connections?* : The database connections to query}';

    protected $description = 'List sessions on the server';

    public function handle(DatabaseManager $db): void
    {
        $connectionNames = (array)$this->argument('connections');
        if (count($connectionNames) === 0) {
            $connectionNames = array_keys((array)config('database.connections'));
        }

        $spannerConnectionNames = array_filter(
            $connectionNames,
            static fn(string $name): bool => config("database.connections.{$name}.driver") === 'spanner',
        );

        $headers = ['Name', 'CreatedAt', 'LastUsedAt'];

        foreach ($spannerConnectionNames as $name) {
            $connection = $db->connection($name);
            if ($connection instanceof SpannerConnection) {
                $sessions = $connection->listSessions();
                $count = count($sessions);
                $data = [];
                $this->info("{$connection->getName()} contains {$count} session(s).");
                foreach ($sessions as $session) {
                    $data[] = [
                        $session->getName(),
                        $session->getCreatedAt(),
                        $session->getLastUsedAt(),
                    ];
                }
                if (count($data) > 0) {
                    $this->table($headers, $data);
                }
            }
        }
    }
}
