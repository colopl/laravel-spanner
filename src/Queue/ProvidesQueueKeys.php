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

namespace Colopl\Spanner\Queue;

/**
 * Implemented by a job that knows the key columns of the interleaved queue it
 * is dispatched to.
 *
 * A dispatched job object is serialized into an opaque blob inside the payload,
 * so the driver cannot find the values there. It asks the job instead, before
 * the payload is built.
 *
 * ```
 * class ProcessUserTask implements ShouldQueue, ProvidesQueueKeys
 * {
 *     public function __construct(private User $user) {}
 *
 *     public function queueKeys(): array
 *     {
 *         return ['UserId' => $this->user->getKey()];
 *     }
 * }
 * ```
 */
interface ProvidesQueueKeys
{
    /**
     * Values for the queue's key columns, keyed by column name.
     *
     * @return array<string, scalar>
     */
    public function queueKeys(): array;
}
