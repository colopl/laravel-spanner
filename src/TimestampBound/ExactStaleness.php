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

class ExactStaleness implements TimestampBoundInterface
{
    /**
     * @var Duration
     */
    public $duration;

    /**
     * Stale reads (i.e. using the bounded or exact staleness types) have the maximum performance benefit at
     * longest staleness intervals. Use a minimum staleness of 10 seconds to get a benefit.
     * @see https://cloud.google.com/spanner/docs/timestamp-bounds?hl=en#timestamp_bound_types
     *
     * @param Duration|int $duration Use a minimum staleness of 10 seconds for best results
     */
    public function __construct($duration)
    {
        if (is_int($duration)) {
            $duration = new Duration($duration);
        }
        $this->duration = $duration;
    }

    /**
     * @inheritDoc
     */
    public function transactionOptions(): array
    {
        return [
            'exactStaleness' => $this->duration,
        ];
    }
}
