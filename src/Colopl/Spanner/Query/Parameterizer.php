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

namespace Colopl\Spanner\Query;

use Exception;
use Illuminate\Support\Str;

/**
 * @internal
 */
class Parameterizer
{
    /**
     * Convert PDO formatted statements to Spanner formatted ones.
     * Something like `WHERE a = ? AND b = ?` will be converted to `WHERE a = @p0 AND b = @p1`
     *
     * Note: NULL and empty arrays do not work well with the converter.
     * It tries to guess the type and gets them wrong so we have to convert them into strings
     * and apply it here before it is passed down to Google's Spanner client.
     *
     * @see https://googlecloudplatform.github.io/google-cloud-php/#/docs/google-cloud/latest/spanner/database?method=execute
     *
     * @param string $query
     * @param array $bindings
     * @return array [0]: converted SQL, [1]: spanner execute options
     * @throws Exception
     */
    public function parameterizeQuery(string $query, array $bindings): array
    {
        $newBindings = [];
        $i = 0;
        $newQuery = preg_replace_callback('/\?/', function () use ($query, $bindings, &$newBindings, &$i) {
            $binding = $bindings[$i];
            $result = null;
            if ($binding === null) {
                $result = 'NULL';
            }
            else if (is_array($binding) && empty($binding)) {
                $result = '[]';
            }
            else if (is_string($binding) && self::hasLikeWildcard($query, $binding)) {
                $result = self::createLikeClause($binding);
            }
            else {
                $placeHolder = 'p'.$i;
                $newBindings[$placeHolder] = $binding;
                $result = "@$placeHolder";
            }
            $i += 1;
            return $result;
        }, $query);

        return [$newQuery, $newBindings];
    }

    /**
     *
     * @param string $query
     * @param string $value
     * @return bool
     */
    private static function hasLikeWildcard(string $query, string $value)
    {
        return Str::contains(strtolower($query), 'like')
            && Str::contains($value, ['%', '_'])
            && (Str::startsWith($value, ['%', '_']) || preg_match('/[^\\\\][%_]/', $value));
    }

    /**
     * @param string $value
     * @return string
     */
    private static function createLikeClause(string $value)
    {
        if (Str::contains($value, "\n")) {
            return "'''".addslashes($value)."'''";
        }
        return "'".addslashes($value)."'";
    }
}