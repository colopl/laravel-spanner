<?php

namespace Colopl\Spanner\Schema;

use Illuminate\Support\Fluent;

/**
 * @property string $name
 * @property int|null $startWithCounter
 * @property int|null $skipRangeMin
 * @property int|null $skipRangeMax
 * @method $this startWithCounter(int $value) set option start_with_counter
 * @method $this skipRangeMin(int $value) set option skip_range_min
 * @method $this skipRangeMax(int $value) set option skip_range_max
 * @extends Fluent<string, mixed>
 */
class SequenceDefinition extends Fluent
{
    public function __construct(string $name)
    {
        parent::__construct(['name' => $name]);
    }

    /**
     * @return array{ sequenceKind: string, startWithCounter?: int, skipRangeMin?: int, skipRangeMax?: int }
     */
    public function getOptions(): array
    {
        return array_filter([
            'sequenceKind' => 'bit_reversed_positive',
            'startWithCounter' => $this->startWithCounter,
            'skipRangeMin' => $this->skipRangeMin,
            'skipRangeMax' => $this->skipRangeMax,
        ], static fn($v) => $v !== null);
    }
}
