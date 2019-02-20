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

use Colopl\Spanner\Session;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
use Google\Auth\FetchAuthTokenInterface;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Spanner\Connection\ConnectionInterface;
use Google\Cloud\Spanner\Connection\Grpc;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\V1\Session as ProtobufSpannerSession;
use Google\Cloud\Spanner\V1\SpannerClient as ProtobufSpannerClient;
use Illuminate\Support\Collection;
use ReflectionException;
use ReflectionObject;

/**
 * @property Database spannerDatabase
 * @method Database getSpannerDatabase
 */
trait ManagesSessionPool
{
    /**
     * Clear the session pool
     * @throws GoogleException
     */
    public function clearSessionPool()
    {
        $sessionPool = $this->getSpannerDatabase()->sessionPool();
        if ($sessionPool !== null) {
            $sessionPool->clear();
        }
    }

    /**
     * Returns the number of warmed up sessions
     * @return int
     * @throws GoogleException
     */
    public function warmupSessionPool()
    {
        $sessionPool = $this->getSpannerDatabase()->sessionPool();
        if(method_exists($sessionPool, 'warmup')) {
            return $sessionPool->warmup();
        }
        return 0;
    }

    /**
     * @return Collection
     * @throws ApiException|ValidationException|GoogleException
     */
    public function listSessions()
    {
        $databaseName = $this->getSpannerDatabase()->name();
        $response = (new ProtobufSpannerClient())->listSessions($databaseName);
        return collect($response->iterateAllElements())->map(function (ProtobufSpannerSession $session) {
            return new Session($session);
        });
    }

    /**
     * @return array
     * @throws ReflectionException
     */
    public function __debugInfo()
    {
        // -------------------------------------------------------------------------
        // HACK: Use reflection to extract some information from a private method
        // -------------------------------------------------------------------------
        /** @var Session|null $session */
        $session = null;
        /** @var FetchAuthTokenInterface $credentialFetcher */
        $credentialFetcher = null;
        $internalConnectionProperty = (new ReflectionObject($this->getSpannerClient()))->getProperty('connection');
        if ($internalConnectionProperty !== null) {
            $internalConnectionProperty->setAccessible(true);
            /** @var ConnectionInterface $internalConnection */
            $internalConnection = $internalConnectionProperty->getValue($this->spannerClient);
            if ($internalConnection instanceof Grpc) {
                $credentialFetcher = $internalConnection->requestWrapper()->getCredentialsFetcher();
            }
        }

        $spannerDatabase = $this->spannerDatabase;
        if ($spannerDatabase !== null) {
            $sessionProperty = (new ReflectionObject($spannerDatabase))->getProperty('session');
            if ($sessionProperty !== null) {
                $sessionProperty->setAccessible(true);
                $session = $sessionProperty->getValue($spannerDatabase);
            }
        }

        return [
            'identity' => $spannerDatabase !== null ? $spannerDatabase->identity() : null,
            'session' => $session !== null ? $session->name() : null,
            'sessionPool' => $spannerDatabase !== null ? $spannerDatabase->sessionPool() : null,
            'credentialFetcher' => $credentialFetcher,
        ];
    }

}
