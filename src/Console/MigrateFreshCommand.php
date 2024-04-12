<?php

namespace Colopl\Spanner\Console;

class MigrateFreshCommand extends MigrateCommand
{
    protected $signature = 'spanner:migrate:fresh {--seed}';

    public function handle(bool $fresh = true):void
    {
        parent::handle(true);
    }
}
