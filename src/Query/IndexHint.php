<?php

declare(strict_types=1);

namespace Colopl\Spanner\Query;

use Illuminate\Database\Query\IndexHint as BaseIndexHint;

class IndexHint extends BaseIndexHint
{
    public bool $disableEmulatorNullFilteredIndexCheck = false;
}
