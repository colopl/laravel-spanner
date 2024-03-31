<?php

namespace Colopl\Spanner\Console;

use Closure;
use Colopl\Spanner\Connection;
use Google\Cloud\Spanner\SpannerClient;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateCommand extends Command
{
    protected $signature = 'spanner:migrate {--seed}';

    public function handle(bool $fresh = false):void
    {
        $connection = DB::connection();

        // check if we not in spanner mode
        if(!$connection instanceof Connection) {
            $command = 'migrate' . ($fresh ? ':fresh' : '');
            $this->call($command, ['--seed' => $this->option('seed')]);
            return;
        }

        // if running emulator, ensure instance exists
        if (!empty(getenv('SPANNER_EMULATOR_HOST'))) {
            $this->info('Checking Emulator Instance');
            $spanner = new SpannerClient((array)$connection->getConfig('client'));
            $instanceName = (string)$connection->getConfig('instance');
            if (! $spanner->instance($instanceName)->exists()) {
                $config = $spanner->instanceConfiguration('emulator-config');
                $spanner->createInstance($config, $instanceName)->pollUntilComplete();
                $this->info('Created Emulator Instance');
            }
        }

        $this->info('Checking DB');
        if(!$connection->databaseExists()) {
            $this->info('Creating DB');
            $connection->createDatabase();
        }

        if($fresh) {
            $this->info('Dropping all tables');
            $connection->getSchemaBuilder()->dropAllTables();
        }

        $this->info('Generating batch DDL');

        /** @var Migrator */
        $migrator = app('migrator');
        $migrationPath = $this->laravel->databasePath().DIRECTORY_SEPARATOR.'migrations';
        $migrations = $migrator->getMigrationFiles($migrationPath);
        $repository = $migrator->getRepository();
        $repositoryExists = $repository->repositoryExists();
        $ran = $repositoryExists ? $repository->getRan() : [];
        $getPending = Closure::bind(fn($files, $ran) => $this->pendingMigrations($files, $ran), $migrator, $migrator);
        $migrations = $getPending($migrations, $ran);

        foreach ($migrations as $path) {
            $this->output->write(str_replace(base_path(), '', $path), true);
        }

        $ddl = [];
        $dml = [];
        $results = $connection->pretend(function() use ($migrator, $migrations, $repository, $repositoryExists) {
            if(!$repositoryExists)
                $repository->createRepository();
            $migrator->runPending($migrations);
        });
    
        foreach($results as $result) {
            $query = $result['query'];
            if(Str::startsWith($query, 'select')) continue;

            if(Str::startsWith($query, ['update', 'insert'])) 
                $dml[] = $result;
            else
                $ddl[] = $query;
        }

        if(count($ddl)) {
            $this->info('Running batch DDL');
            $connection->runDdlBatch($ddl);
        } else
            $this->info('Nothing to migrate');

        if(count($dml)) {
            $this->info('Running DML');
            foreach ($dml as $d) {
                $connection->statement($d['query'], $d['bindings']);
            }
        }

        if($this->option('seed'))
            $this->call('db:seed');
        
        $this->info("Done");
    }
}