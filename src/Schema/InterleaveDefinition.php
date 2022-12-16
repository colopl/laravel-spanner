<?php

namespace Colopl\Spanner\Schema;

use Illuminate\Support\Fluent;

/**
 * @method $this onDelete(string $action) Add an ON DELETE action
 * @extends Fluent<string, mixed>
 */
class InterleaveDefinition extends Fluent
{
    /**
     * @return $this
     */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('cascade');
    }
}
