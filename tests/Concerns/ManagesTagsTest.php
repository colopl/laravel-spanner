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

namespace Colopl\Spanner\Tests\Concerns;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Tests\TestCase;

class ManagesTagsTest extends TestCase
{
    public function test_set_and_get_requestTag(): void
    {
        $conn = $this->getConnection();
        assert($conn instanceof Connection);
        $conn->setRequestTag('url=/api/users');
        $this->assertSame('url=/api/users', $conn->getRequestTag());
        $conn->setRequestTag(null);
        $this->assertNull($conn->getRequestTag());
    }
//
//    public function test_set_and_get_transactionTag(): void
//    {
//        $conn = $this->getDefaultConnection();
//        $conn->setTransactionTag('url=/api/users/update');
//        $this->assertSame('url=/api/users/update', $conn->getTransactionTag());
//        $conn->setTransactionTag(null);
//        $this->assertNull($conn->getTransactionTag());
//    }
}
