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

namespace Colopl\Spanner\Query\Concerns;

use Colopl\Spanner\TimestampBound\TimestampBoundInterface;

trait UsesStaleReads
{
    /**
     * @var TimestampBoundInterface|null
     */
    protected $timestampBound;

    /**
     * @param TimestampBoundInterface $timestampBound
     * @return $this
     */
    public function withStaleness(TimestampBoundInterface $timestampBound)
    {
        $this->timestampBound = $timestampBound;
        return $this;
    }
}
