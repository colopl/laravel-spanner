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

use Closure;
use Colopl\Spanner\TimestampBound\TimestampBoundInterface;
use Google\Cloud\Spanner\Snapshot;
use LogicException;

trait ManagesSnapshots
{
    /**
     * @var Snapshot|null
     */
    protected ?Snapshot $currentSnapshot = null;

    /**
     * @template TReturn
     * @param TimestampBoundInterface $timestampBound
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function snapshot(TimestampBoundInterface $timestampBound, Closure $callback): mixed
    {
        if ($this->currentSnapshot !== null) {
            throw new LogicException('Nested snapshots are not supported.');
        }

        $options = $timestampBound->transactionOptions();
        try {
            $this->currentSnapshot = $this->getSpannerDatabase()->snapshot($options);
            return $callback();
        } finally {
            $this->currentSnapshot = null;
        }
    }

    /**
     * @return bool
     */
    public function inSnapshot(): bool
    {
        return $this->currentSnapshot !== null;
    }
}
