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

class WarmupCommandTest extends TestCase
{
    public function test_no_args(): void
    {
        foreach (['main', 'alternative'] as $name) {
            $conn = $this->getConnection($name);
            assert($conn instanceof Connection);
            $this->setUpDatabaseOnce($conn);
        }

        // Emulator cannot distinguish different connections
        $altWarmupCount = env('SPANNER_EMULATOR_HOST') ? 0 : 1;

        $this->artisan('spanner:warmup')
            ->expectsOutputToContain("Warmed up 1 sessions for main")
            ->expectsOutputToContain("Warmed up {$altWarmupCount} sessions for alternative")
            ->assertSuccessful()
            ->run();

        $this->artisan('spanner:warmup')
            ->expectsOutputToContain("Warmed up 0 sessions for main")
            ->expectsOutputToContain("Warmed up 0 sessions for alternative")
            ->assertSuccessful()
            ->run();
    }

    public function test_with_args(): void
    {
        foreach (['main', 'alternative'] as $name) {
            $conn = $this->getConnection($name);
            assert($conn instanceof Connection);
            $this->setUpDatabaseOnce($conn);
        }

        $this->artisan('spanner:warmup', ['connections' => 'main'])
            ->expectsOutputToContain("Warmed up 1 sessions for main")
            ->doesntExpectOutputToContain("alternative")
            ->assertSuccessful()
            ->run();
    }
}
