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

use DateTimeInterface;
use Google\Cloud\Spanner\Timestamp;

class MinReadTimestamp implements TimestampBoundInterface
{
    /**
     * @var Timestamp
     */
    public $timestamp;

    /**
     * @param Timestamp|DateTimeInterface $timestamp
     */
    public function __construct($timestamp)
    {
        if ($timestamp instanceof DateTimeInterface) {
            $timestamp = new Timestamp($timestamp);
        }
        $this->timestamp = $timestamp;
    }

    /**
     * @inheritDoc
     */
    public function transactionOptions(): array
    {
        return [
            'minReadTimestamp' => $this->timestamp,
        ];
    }
}
