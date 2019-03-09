<?php

namespace Colopl\Spanner\Console;

use Illuminate\Console\ConfirmableTrait;
use Colopl\Spanner\Migrations\Migrator;
use Symfony\Component\Console\Input\InputOption;

class ResetCommand extends BaseCommand
{
    use ConfirmableTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'spanner:migrate:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback all database migrations';

    /**
     * The migrator instance.
     *
     * @var \Colopl\Spanner\Migrations\Migrator
     */
    protected $migrator;

    /**
     * Create a new migration rollback command instance.
     *
     * @param  \Colopl\Spanner\Migrations\Migrator  $migrator
     * @return void
     */
    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->migrator = $migrator;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return;
        }

        $this->migrator->setConnection($this->option('database'));

        // First, we'll make sure that the migration table actually exists before we
        // start trying to rollback and re-run all of the migrations. If it's not
        // present we'll just bail out with an info message for the developers.
        if (! $this->migrator->repositoryExists()) {
            return $this->comment('Migration table not found.');
        }

        $this->migrator->setOutput($this->output)->reset(
            $this->getMigrationPaths(), $this->option('pretend')
        );
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to use'],

            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],

            ['path', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The path(s) to the migrations files to be executed'],

            ['realpath', null, InputOption::VALUE_NONE, 'Indicate any provided migration file paths are pre-resolved absolute paths'],

            ['pretend', null, InputOption::VALUE_NONE, 'Dump the SQL queries that would be run'],
        ];
    }
}