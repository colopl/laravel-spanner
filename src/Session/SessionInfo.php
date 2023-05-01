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
    protected string $fullName;

    protected string $name;

    protected Carbon $createdAt;

    protected Carbon $lastUsedAt;

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

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): Carbon
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): Carbon
    {
        return $this->lastUsedAt;
    }
}
