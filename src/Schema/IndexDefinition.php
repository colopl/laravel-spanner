<?php

namespace Colopl\Spanner\Schema;

use Illuminate\Support\Fluent;

/**
 * @method $this interleaveIn(string $table)
 * @method $this nullFiltered()
 * @method $this storing(string[] $columns)
 * @extends Fluent<string, mixed>
 */
class IndexDefinition extends Fluent
{
    /**
     * @deprecated use interleaveIn instead.
     * @return $this
     */
    public function interleave(string $table): static
    {
        return $this->interleaveIn($table);
    }
}
