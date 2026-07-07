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

namespace Colopl\Spanner;

use Illuminate\Support\Facades\DB;

class SpannerSessionRefresher
{
    private const int|float INTERVAL_IN_SECONDS = 24 * 60 * 60;

    /**
     * @var int
     */
    private int $lastRefreshedAt;

    public function __construct()
    {
        $this->lastRefreshedAt = time();
    }

    /**
     * @return void
     */
    public function refreshIfNeeded(): void
    {
        $now = time();
        if (($now - $this->lastRefreshedAt) <= self::INTERVAL_IN_SECONDS) {
            return;
        }

        foreach (DB::getConnections() as $connection) {
            if ($connection instanceof Connection) {
                $connection->refreshSession();
            }
        }

        $this->lastRefreshedAt = $now;
    }
}
