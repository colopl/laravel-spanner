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

namespace Colopl\Spanner\Tests\Queue;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Events\MutatingData;
use Colopl\Spanner\Session\CacheSessionPool;
use Colopl\Spanner\Session\SessionInfo;
use Colopl\Spanner\Tests\TestCase;
use Colopl\Spanner\TimestampBound\ExactStaleness;
use Colopl\Spanner\TimestampBound\MaxStaleness;
use Colopl\Spanner\TimestampBound\MinReadTimestamp;
use Colopl\Spanner\TimestampBound\ReadTimestamp;
use Colopl\Spanner\TimestampBound\StrongRead;
use Google\Auth\FetchAuthTokenInterface;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Spanner\Transaction;
use Illuminate\Bus\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class QueueEventTest extends TestCase
{
    public function test_disconnect_after_job(): void
    {
        $this->getDefaultConnection();

        dispatch(new QueryJob());

        self::assertCount(1, DB::getConnections());
        self::assertInstanceOf(Connection::class, DB::getConnections()['main']);
        self::assertFalse(DB::getConnections()['main']->isConnected());
    }
}
