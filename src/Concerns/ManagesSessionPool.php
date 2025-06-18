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
use Google\Cloud\Spanner\Connection\Grpc;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Session\Session as CloudSpannerSession;
use Google\Cloud\Spanner\V1\Session as ProtobufSpannerSession;
use Google\Cloud\Spanner\V1\SpannerClient as ProtobufSpannerClient;
use Illuminate\Support\Collection;
use ReflectionException;
use ReflectionObject;

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
        $sessionPool = $this->getSpannerDatabase()->sessionPool();
        $sessionPool?->clear();
    }

    /**
     * @return bool
     */
    public function maintainSessionPool(): bool
    {
        $sessionPool = $this->getSpannerDatabase()->sessionPool();
        if ($sessionPool !== null && method_exists($sessionPool, 'maintain')) {
            $sessionPool->maintain();
            return true;
        }
        return false;
    }

    /**
     * @return int  Number of warmed up sessions
     */
    public function warmupSessionPool(): int
    {
        $sessionPool = $this->getSpannerDatabase()->sessionPool();
        if ($sessionPool !== null && method_exists($sessionPool, 'warmup')) {
            return $sessionPool->warmup();
        }
        return 0;
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

        $response = (new ProtobufSpannerClient($config))->listSessions($databaseName);

        $sessions = [];
        foreach ($response->iterateAllElements() as $session) {
            assert($session instanceof ProtobufSpannerSession);
            $sessions[] = new SessionInfo($session);
        }
        return new Collection($sessions);
    }

    /**
     * @return array<string, mixed>
     * @throws ReflectionException
     */
    public function __debugInfo()
    {
        // -------------------------------------------------------------------------
        // HACK: Use reflection to extract some information from a private method
        // -------------------------------------------------------------------------
        $session = null;
        $credentialFetcher = null;

        $internalConnectionProperty = (new ReflectionObject($this->getSpannerClient()))->getProperty('connection');
        $internalConnectionProperty->setAccessible(true);
        $internalConnection = $internalConnectionProperty->getValue($this->spannerClient);
        if ($internalConnection instanceof Grpc) {
            $requestWrapper = $internalConnection->requestWrapper();
            $credentialFetcher = $requestWrapper?->getCredentialsFetcher();
        }

        $spannerDatabase = $this->spannerDatabase;
        if ($spannerDatabase !== null) {
            $sessionProperty = (new ReflectionObject($spannerDatabase))->getProperty('session');
            $sessionProperty->setAccessible(true);
            $session = $sessionProperty->getValue($spannerDatabase);
            assert($session instanceof CloudSpannerSession);
        }

        return [
            'identity' => $spannerDatabase?->identity(),
            'session' => $session?->name(),
            'sessionPool' => $spannerDatabase?->sessionPool(),
            'credentialFetcher' => $credentialFetcher,
        ];
    }

}
