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

namespace Colopl\Spanner\Schema;

use Illuminate\Support\Fluent;

/**
 * @see https://cloud.google.com/spanner/docs/reference/standard-sql/data-definition-language#queue_statements
 *
 * @property bool|null $disableSend
 * @property bool|null $disableDelivery
 * @property string|null $localityGroup
 * @method $this disableSend(bool|null $disableSend)
 * @method $this disableDelivery(bool|null $disableDelivery)
 * @method $this localityGroup(string|null $localityGroup)
 * @extends Fluent<string, mixed>
 */
class QueueDefinition extends Fluent
{
    /**
     * @param string $name name of the schema command (e.g. `createQueue`)
     */
    public function __construct(
        public string $name,
    ) {
        parent::__construct();
    }

    /**
     * Options that Spanner accepts in `create queue ... options (...)` and
     * `alter queue ... set options (...)`.
     *
     * `receive_mode` is intentionally not exposed since `'PULL'` is currently
     * its only valid value, and is also the default.
     *
     * @return array<string, scalar|null>
     */
    public function getOptions(): array
    {
        $attributes = $this->getAttributes();

        $options = [];
        // Options which were never touched are omitted, but options explicitly
        // set to null are kept, since `alter queue` uses null to reset an
        // option back to its default.
        foreach (['disableSend', 'disableDelivery', 'localityGroup'] as $option) {
            if (array_key_exists($option, $attributes)) {
                /** @var scalar|null $value */
                $value = $attributes[$option];
                $options[$option] = $value;
            }
        }
        return $options;
    }
}
