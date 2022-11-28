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

use Exception;
use Google\Cloud\Core\LongRunning\LongRunningOperation;
use Google\Cloud\Spanner\Database;

/**
 * @method Database getSpannerDatabase()
 */
trait ManagesLongRunningOperations
{
    /**
     * @var float
     */
    protected $longRunningOperationTimeoutSeconds = 0.0;

    /**
     * @param LongRunningOperation $operation
     * @return mixed
     */
    public function waitForOperation(LongRunningOperation $operation)
    {
        $result = $operation->pollUntilComplete(['maxPollingDurationSeconds' => $this->longRunningOperationTimeoutSeconds]);
        if ($operation->error() !== null) {
            throw new Exception((string) json_encode($operation->error()));
        }
        return $result;
    }

    /**
     * @param LongRunningOperation $operation
     * @return bool
     */
    public function isDoneOperation(LongRunningOperation $operation): bool
    {
        $operation->reload(['maxPollingDurationSeconds' => $this->longRunningOperationTimeoutSeconds]);
        if ($operation->error() !== null) {
            throw new Exception((string) json_encode($operation->error()));
        }

        $endTime = microtime(true) + $this->longRunningOperationTimeoutSeconds;
        $hasMaxPollingDuration = $this->longRunningOperationTimeoutSeconds > 0.0;

        return ($operation->done() || ($hasMaxPollingDuration && microtime(true) >= $endTime));
    }
}
