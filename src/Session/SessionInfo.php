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

namespace Colopl\Spanner\Session;

use Google\Cloud\Spanner\V1\Session as ProtoBufSession;
use Illuminate\Support\Carbon;

class SessionInfo
{
    /**
     * @var string
     */
    protected $fullName;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var Carbon
     */
    protected $createdAt;

    /**
     * @var Carbon
     */
    protected $lastUsedAt;

    /**
     * @var array<string, string>
     */
    protected array $labels;

    /**
     * @param ProtoBufSession $protobufSession
     */
    public function __construct(ProtoBufSession $protobufSession)
    {
        $this->fullName = $protobufSession->getName();
        $this->name = collect(explode('/', $protobufSession->getName()))->last() ?? 'undefined';
        if (($createTime = $protobufSession->getCreateTime()) !== null) {
            $this->createdAt = Carbon::instance($createTime->toDateTime());
        }
        if (($approximateLastUseTime = $protobufSession->getApproximateLastUseTime()) !== null) {
            $this->lastUsedAt = Carbon::instance($approximateLastUseTime->toDateTime());
        }
        /** @var array<string, string> $labels */
        $labels = iterator_to_array($protobufSession->getLabels());
        $this->labels = $labels;
    }

    /**
     * @return string
     */
    public function getFullName(): string
    {
        return $this->fullName;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return Carbon|null
     */
    public function getCreatedAt(): ?Carbon
    {
        return $this->createdAt;
    }

    /**
     * @return Carbon|null
     */
    public function getLastUsedAt(): ?Carbon
    {
        return $this->lastUsedAt;
    }

    /**
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }
}
