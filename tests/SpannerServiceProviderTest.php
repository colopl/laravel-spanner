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

namespace Colopl\Spanner\Tests;

use Illuminate\Database\DatabaseManager;

class SpannerServiceProviderTest extends TestCase
{
    public function test_change_cache_path(): void
    {
        $newPath = '/tmp/spanner';
        config()->set('database.connections.main.cache_path', $newPath);
        $this->beforeApplicationDestroyed(static fn () => shell_exec('rm -rf {$newPath}'));

        /** @var DatabaseManager $db */
        $db = $this->app->make('db');

        $db->connection('main')->query()->select('SELECT 1');

        $this->assertDirectoryExists($newPath);
        $this->assertDirectoryExists("{$newPath}/main_sessions");
        $this->assertDirectoryExists("{$newPath}/main_auth");
    }
}
