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

namespace Colopl\Spanner\Concerns;

use Colopl\Spanner\Session\SessionInfo;
use Google\Cloud\Core\EmulatorTrait;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\V1\Client\SpannerClient;
use Google\Cloud\Spanner\V1\ListSessionsRequest;
use Google\Cloud\Spanner\V1\Session;
use Illuminate\Support\Collection;

trait ManagesSessionPool
{
    use EmulatorTrait;

    /**
     * @return Database
     */
    abstract public function getSpannerDatabase(): Database;

    /**
     * @return void
     */
    public function clearSessionPool(): void
    {
        $this->getSpannerDatabase()->session()->refresh();
    }

    /**
     * @return int  Number of warmed up sessions
     */
    public function warmupSessionPool(): int
    {
        // Use name() instead of refresh() so that an existing valid session
        // stored in the FilesystemAdapter cache is reused rather than
        // unconditionally replaced with a newly-created one.
        $this->getSpannerDatabase()->session()->name();
        return 1;
    }

    /**
     * @return Collection<int, SessionInfo>
     */
    public function listSessions(): Collection
    {
        $databaseName = $this->getSpannerDatabase()->name();
        $emulatorHost = getenv('SPANNER_EMULATOR_HOST');
        $config = $emulatorHost
            ? $this->emulatorGapicConfig($emulatorHost)
            : [];

        $request = new ListSessionsRequest();
        $request->setDatabase($databaseName);
        $response = (new SpannerClient($config))->listSessions($request);

        $sessions = [];
        foreach ($response as $session) {
            assert($session instanceof Session);
            $sessions[] = new SessionInfo($session);
        }
        return new Collection($sessions);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo()
    {
        $session = null;

        $spannerDatabase = $this->spannerDatabase;
        if ($spannerDatabase !== null) {
            $sessionCache = $spannerDatabase->session();
            $session = $sessionCache->name();
        }

        return [
            'identity' => $spannerDatabase?->identity(),
            'session' => $session,
        ];
    }

}
