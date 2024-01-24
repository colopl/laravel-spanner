<?php

namespace Colopl\Spanner\Schema;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition as BaseColumnDefinition;

class ColumnDefinition extends BaseColumnDefinition
{
    public function generateUuid(): static
    {
        return $this->default(new Expression('generate_uuid()'));
    }
}
