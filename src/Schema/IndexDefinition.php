<?php

namespace Colopl\Spanner\Schema;

use Illuminate\Support\Fluent;

/**
 * @method $this interleave(string $table)
 * @method $this nullFiltered()
 * @method $this storing(string[] $columns)
 * @extends Fluent<string, mixed>
 */
class IndexDefinition extends Fluent
{
}
