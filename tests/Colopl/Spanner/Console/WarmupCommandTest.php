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
use Colopl\Spanner\Tests\TestCase;
use Illuminate\Testing\PendingCommand;

class WarmupCommandTest extends TestCase
{
    public function test_no_args(): void
    {
        foreach (['main', 'alternative'] as $name) {
            $conn = $this->getConnection($name);
            assert($conn instanceof Connection);
            $this->setUpDatabaseOnce($conn);
        }

        $command = $this->artisan('spanner:warmup');
        assert($command instanceof PendingCommand);

        // Emulator cannot distinguish different connections
        $altWarmupCount = env('SPANNER_EMULATOR_HOST') ? 0 : 1;

        $command
            ->expectsOutputToContain("Warmed up 1 sessions for main")
            ->expectsOutputToContain("Warmed up {$altWarmupCount} sessions for alternative")
            ->assertSuccessful()
            ->run();

        $command2 = $this->artisan('spanner:warmup');
        assert($command2 instanceof PendingCommand);
        $command2
            ->expectsOutputToContain("Warmed up 0 sessions for main")
            ->expectsOutputToContain("Warmed up 0 sessions for alternative")
            ->assertSuccessful()
            ->run();
    }
}
