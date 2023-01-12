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

use Colopl\Spanner\Connection;
use Colopl\Spanner\Connection as SpannerConnection;
use Colopl\Spanner\Session;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class SessionsCommand extends Command
{
    protected $signature = 'spanner:sessions {connections?* : The database connections to query}
               {--sort=LastUsed : Name of column to be sorted [Name, Created, LastUsed]}
               {--order=desc : Sort order as "asc" or "desc"}';

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

        foreach ($spannerConnectionNames as $name) {
            $connection = $db->connection($name);
            if ($connection instanceof SpannerConnection) {
                $sessions = $this->makeSessionData($connection);
                $this->info("{$connection->getName()} contains {$sessions->count()} session(s).");
                if ($sessions->isNotEmpty()) {
                    $headers = array_keys($sessions[0]);
                    $this->table($headers, $sessions);
                }
            }
        }
    }

    /**
     * @param Connection $connection
     * @return Collection<int, array{ Name: string, Created: string, LastUsed: string }>
     */
    protected function makeSessionData(Connection $connection): Collection
    {
        $descending = $this->getOrder() === 'desc';

        return $connection->listSessions()
            ->sortBy(fn(Session $s) => $this->getSortValue($s), descending: $descending)
            ->map(static fn(Session $s) => [
                'Name' => $s->getName(),
                'Created' => $s->getCreatedAt()->format('Y-m-d H:i:s'),
                'LastUsed' => $s->getLastUsedAt()->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * @param Session $session
     * @return string
     */
    protected function getSortValue(Session $session): string
    {
        $sort = $this->option('sort');
        assert(is_string($sort));
        return match (Str::studly($sort)) {
            'Name' => $session->getName(),
            'Created' => (string) $session->getCreatedAt(),
            'LastUsed' => (string) $session->getLastUsedAt(),
            default => throw new RuntimeException("Unknown column: {$sort}"),
        };
    }

    /**
     * @return string
     */
    protected function getOrder(): string
    {
        $order = $this->option('order');
        assert(is_string($order));

        $order = strtolower($order);

        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new RuntimeException("Unknown order: {$order}. Must be [ASC, DESC]");
        }

        return $order;
    }

}
