<?php

namespace Colopl\Spanner\Console;

class MigrateFreshCommand extends MigrateCommand
{
    protected $signature = 'spanner:migrate:fresh {--seed}';

    public function handle($fresh = true)
    {
        parent::handle(true);
    }
}
