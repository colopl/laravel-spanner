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

$conn = [
    'driver' => 'spanner',
    'instance' => getenv('DB_SPANNER_INSTANCE_ID'),
    'database' => getenv('DB_SPANNER_DATABASE_ID'),

    'client' => [
        'projectId' => getenv('DB_SPANNER_PROJECT_ID'),
        'requestTimeout' => 600,
    ],

    'session_pool' => [
        'minSessions' => 1,
        'maxSessions' => 100,
    ],
];

return [
    'connections' => [
        'main' => $conn,
        'alternative' => ['database' => $conn['database'] . '-alt'] + $conn,
    ],
    'default' => 'main',
];
