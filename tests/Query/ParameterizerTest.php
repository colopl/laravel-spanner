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

namespace Colopl\Spanner\Tests\Query;

use Colopl\Spanner\Query\Parameterizer;
use Colopl\Spanner\Tests\TestCase;

class ParameterizerTest extends TestCase
{
    public function testParameterizeQuery(): void
    {
        $parameterizer = new Parameterizer();
        $bindings = [$this->generateUuid(), 'test-name', null, 0, [1, 2, 3], []];
        [$query, $newBindings] = $parameterizer->parameterizeQuery('INSERT INTO `Test` (ID, Name, Nullable, Int, IntList, EmptyList) VALUES (?, ?, ?, ?, ?, ?)', $bindings);
        $this->assertEquals('INSERT INTO `Test` (ID, Name, Nullable, Int, IntList, EmptyList) VALUES (@p0, @p1, NULL, @p3, @p4, [])', $query);
        $this->assertEquals($bindings[0], $newBindings['p0']);
        $this->assertEquals($bindings[1], $newBindings['p1']);
        $this->assertEquals($bindings[3], $newBindings['p3']);
        $this->assertEquals($bindings[4], $newBindings['p4']);
    }

    /**
     * @see https://cloud.google.com/spanner/docs/functions-and-operators#comparison-operators
     */
    public function testParameterizeLikeClause(): void
    {
        $parameterizer = new Parameterizer();

        // \% (escaped) should be treated as normal string and should be converted to @p0
        // % should be treated as multi-string wildcard
        $bindings = ['normal\%string', '\'--injection%', '%'.chr(0xbf).chr(0x27), 'test'];
        [$query, $newBindings] = $parameterizer->parameterizeQuery('SELECT * FROM `User` WHERE `Col1` LIKE ? AND `Col2` LIKE ? AND `Col3` LIKE ? AND `Col4` LIKE ?', $bindings);
        $this->assertEquals("SELECT * FROM `User` WHERE `Col1` LIKE @p0 AND `Col2` LIKE '\'--injection%' AND `Col3` LIKE '%\xbf\\\x27' AND `Col4` LIKE @p3", $query);
        $this->assertEquals($bindings[0], $newBindings['p0']);

        // queries that do not have LIKE will not embed anything
        [$query, $newBindings] = $parameterizer->parameterizeQuery('INSERT INTO `User` (`ID`, `Name`) VALUES (?, ?, ?, ?)', $bindings);
        $this->assertEquals("INSERT INTO `User` (`ID`, `Name`) VALUES (@p0, @p1, @p2, @p3)", $query);

        // \_ (escaped) should be treated as normal string and should be converted to @p0
        // _ should be treated as single-string wildcard
        $bindings = ['normal\_string', 'wildcard_', '_wildcard', 'wil_card'];
        [$query, $newBindings] = $parameterizer->parameterizeQuery('LIKE ? ? ? ?', $bindings);
        $this->assertEquals("LIKE @p0 'wildcard_' '_wildcard' 'wil_card'", $query);
    }

    /**
     * strings that include new lines should be triple-quoted
     * @see https://cloud.google.com/spanner/docs/lexical?hl=en#string-and-bytes-literals
     */
    public function testParameterContainsNewLine(): void
    {
        $parameterizer = new Parameterizer();

        $bindings = ["%\ntest"];
        [$query, $options] = $parameterizer->parameterizeQuery('LIKE ?', $bindings);

        $this->assertEquals("LIKE '''%\ntest'''", $query);
    }
}
