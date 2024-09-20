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

declare(strict_types=1);

namespace Colopl\Spanner\Schema;

use Illuminate\Support\Fluent;

/**
 * @property string $retentionPeriod
 * @property ChangeStreamValueCaptureType $valueCaptureType
 * @property bool $excludeTtlDeletes
 * @property bool $excludeInsert
 * @property bool $excludeUpdate
 * @property bool $excludeDelete
 * @method $this retentionPeriod(string $retentionPeriod)
 * @method $this valueCaptureType(ChangeStreamValueCaptureType $valueCaptureType)
 * @method $this excludeTtlDeletes(bool $excludeTtlDeletes)
 * @method $this excludeInsert(bool $excludeInsert)
 * @method $this excludeUpdate(bool $excludeUpdate)
 * @method $this excludeDelete(bool $excludeDelete)
 * @extends Fluent<string, scalar>
 */
class ChangeStreamDefinition extends Fluent
{
    /**
     * @param string $name
     * @param string $stream
     * @param array<string, list<string>> $tables
     */
    public function __construct(
        public string $name,
        public string $stream,
        public array $tables = [],
    ) {
        parent::__construct();
    }

    /**
     * @param string $table
     * @param list<string> $columns
     * @return $this
     */
    public function for(string $table, array $columns = []): static
    {
        $this->tables[$table] = $columns;
        return $this;
    }

    /**
     * @return array{
     *     retentionPeriod: string,
     *     valueCaptureType?: ChangeStreamValueCaptureType,
     *     excludeTtlDeletes?: bool,
     *     excludeInsert?: bool,
     *     excludeUpdate?: bool,
     *     excludeDelete?: bool,
     * }
     */
    public function getOptions(): array
    {
        return array_filter([
            'retentionPeriod' => $this->retentionPeriod,
            'valueCaptureType' => $this->valueCaptureType,
            'excludeTtlDeletes' => $this->excludeTtlDeletes,
            'excludeInsert' => $this->excludeInsert,
            'excludeUpdate' => $this->excludeUpdate,
            'excludeDelete' => $this->excludeDelete,
        ], static fn($v) => $v !== null);
    }
}
