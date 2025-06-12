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

trait ManagesTagging
{
    /**
     * @var string|null
     */
    protected ?string $requestTag = null;

    /**
     * @var string|null
     */
    protected ?string $transactionTag = null;

    /**
     * @param string|null $tag
     * @return $this
     */
    public function setRequestTag(?string $tag): static
    {
        $this->requestTag = $tag;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRequestTag(): ?string
    {
        return $this->requestTag;
    }

    /**
     * @param string|null $tag
     * @return $this
     */
    public function setTransactionTag(?string $tag): static
    {
        $this->transactionTag = $tag;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getTransactionTag(): ?string
    {
        return $this->transactionTag;
    }
}
