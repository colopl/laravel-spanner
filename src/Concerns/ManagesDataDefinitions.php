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

use Google\Cloud\Core\LongRunning\LongRunningOperation;
use Google\Cloud\Spanner\Database;
use RuntimeException;
use function json_encode;

/**
 * @method Database getSpannerDatabase()
 */
trait ManagesDataDefinitions
{
    /**
     * @param string $ddl
     * @return LongRunningOperation
     */
    public function runDdl(string $ddl): LongRunningOperation
    {
        return $this->getSpannerDatabase()->updateDdl($ddl);
    }

    /**
     * @param string[] $ddls
     * @return LongRunningOperation
     */
    public function runDdls(array $ddls): LongRunningOperation
    {
        return $this->getSpannerDatabase()->updateDdlBatch($ddls);
    }

    /**
     * @param list<string> $statements
     * @return mixed
     */
    public function runDdlBatch(array $statements): mixed
    {
        $start = microtime(true);

        $result = $this->waitForOperation(
            $this->getSpannerDatabase()->updateDdlBatch($statements),
        );

        foreach ($statements as $statement) {
            $this->logQuery($statement, [], $this->getElapsedTime($start));
        }

        return $result;
    }

    /**
     * @param string[] $statements Additional DDL statements
     * @return void
     */
    public function createDatabase(array $statements = [])
    {
        $start = microtime(true);

        $this->waitForOperation(
            $this->getSpannerDatabase()->create(['statements' => $statements]),
        );

        foreach ($statements as $statement) {
            $this->logQuery($statement, [], $this->getElapsedTime($start));
        }
    }

    /**
     * @return void
     */
    public function dropDatabase()
    {
        $this->getSpannerDatabase()->drop();
    }

    /**
     * @return bool
     */
    public function databaseExists()
    {
        return $this->getSpannerDatabase()->exists();
    }

    /**
     * @param LongRunningOperation $operation
     * @return mixed
     */
    protected function waitForOperation(LongRunningOperation $operation): mixed
    {
        $result = $operation->pollUntilComplete(['maxPollingDurationSeconds' => 0.0]);
        if ($operation->error() !== null) {
            throw new RuntimeException((string) json_encode($operation->error()));
        }
        return $result;
    }
}
