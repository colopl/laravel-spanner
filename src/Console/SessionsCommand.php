<?php

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
