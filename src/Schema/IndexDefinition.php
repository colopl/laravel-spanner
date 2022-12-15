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
     * @param string $table
     * @return $this
     */
    public function interleave(string $table)
    {
        return $this->interleaveIn($table);
    }
}
