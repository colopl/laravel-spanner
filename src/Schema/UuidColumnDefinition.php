<?php

namespace Colopl\Spanner\Schema;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition as BaseColumnDefinition;

class UuidColumnDefinition extends BaseColumnDefinition
{
    /**
     * @return $this
     */
    public function generateUuid(): static
    {
        return $this->default(new Expression('generate_uuid()'));
    }
}
