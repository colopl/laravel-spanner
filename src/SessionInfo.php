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

namespace Colopl\Spanner;

use Illuminate\Support\Carbon;
use Google\Cloud\Spanner\V1\Session as ProtoBufSession;

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
     * @return Carbon
     */
    public function getCreatedAt(): Carbon
    {
        return $this->createdAt;
    }

    /**
     * @return Carbon
     */
    public function getLastUsedAt(): Carbon
    {
        return $this->lastUsedAt;
    }
}
