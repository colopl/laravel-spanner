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

namespace Colopl\Spanner\TimestampBound;

use Google\Cloud\Spanner\Duration;
use Google\Cloud\Spanner\Timestamp;

/**
 * TimestampBound defines how Cloud Spanner will choose a timestamp for a single read/query or read-only transaction.
 */
interface TimestampBoundInterface
{
    /**
     * transactionOptions is used for $options on read/query or read-only transaction (ex. Database::snapshot)
     *
     * @see https://cloud.google.com/spanner/docs/reference/rpc/google.spanner.v1#google.spanner.v1.TransactionOptions
     * 
     * @return array{
     *     returnReadTimestamp?: bool,
     *     strong?: bool,
     *     minReadTimestamp?: Timestamp,
     *     maxStaleness?: Duration,
     *     readTimestamp?: Timestamp,
     *     exactStaleness?: Duration,
     * }
     */
    public function transactionOptions(): array;
}
