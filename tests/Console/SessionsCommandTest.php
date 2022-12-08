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

namespace Colopl\Spanner\Tests\Console;

use Colopl\Spanner\Connection;
use Illuminate\Support\Facades\Artisan;

class SessionsCommandTest extends TestCase
{
    public function test_no_args(): void
    {
        if (getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot list sessions on emulator');
        }

        foreach (['main', 'alternative'] as $name) {
            $conn = $this->getConnection($name);
            assert($conn instanceof Connection);
            $this->setUpDatabaseOnce($conn);
        }

        $this->artisan('spanner:warmup')
            ->assertSuccessful()
            ->run();

        $this->artisan('spanner:sessions')
            ->expectsOutputToContain('main contains 1 session(s).')
            ->expectsOutputToContain('alternative contains 1 session(s).')
            ->assertSuccessful()
            ->run();
    }

    public function test_with_args(): void
    {
        if (getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot list sessions on emulator');
        }

        foreach (['main', 'alternative'] as $name) {
            $conn = $this->getConnection($name);
            assert($conn instanceof Connection);
            $this->setUpDatabaseOnce($conn);
        }

        $this->artisan('spanner:sessions', ['connections' => 'main'])
            ->expectsOutputToContain('main contains 1 session(s).')
            ->doesntExpectOutputToContain('alternative')
            ->assertSuccessful()
            ->run();
    }
}
