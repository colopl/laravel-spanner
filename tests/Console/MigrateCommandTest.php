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

namespace Console;

use Colopl\Spanner\Tests\Console\TestCase;
use Colopl\Spanner\Tests\DatabaseSeeder;

class MigrateCommandTest extends TestCase
{
    public function test_no_args(): void
    {
        $this->artisan('spanner:migrate')
            ->expectsOutputToContain("Checking DB")
            ->expectsOutputToContain("Generating batch DDL")
            ->expectsOutputToContain("Done")
            ->doesntExpectOutputToContain("Dropping all tables")
            ->doesntExpectOutputToContain("Seeding database")
            ->assertSuccessful()
            ->run();
    }

    public function test_with_seed(): void
    {
        if(!class_exists('DatabaseSeeder'))
            class_alias(DatabaseSeeder::class, 'DatabaseSeeder');

        $this->artisan('spanner:migrate', ['--seed' => true])
            ->expectsOutputToContain("Checking DB")
            ->expectsOutputToContain("Generating batch DDL")
            ->expectsOutputToContain("Seeding database")
            ->expectsOutputToContain("Done")
            ->doesntExpectOutputToContain("Dropping all tables")
            ->assertSuccessful()
            ->run();
    }
}
