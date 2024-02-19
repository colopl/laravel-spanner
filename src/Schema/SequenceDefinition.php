<?php

namespace Colopl\Spanner\Schema;

use Colopl\Spanner\Support\Ensure;
use Illuminate\Support\Fluent;

/**
 * @property string $name
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
        $options = [
            'sequenceKind' => 'bit_reversed_positive',
        ];
        if (array_key_exists('startWithCounter', $this->attributes)) {
            $options['startWithCounter'] = Ensure::int($this->attributes['startWithCounter']);
        }
        if (array_key_exists('skipRangeMin', $this->attributes)) {
            $options['skipRangeMin'] = Ensure::int($this->attributes['skipRangeMin']);
        }
        if (array_key_exists('skipRangeMax', $this->attributes)) {
            $options['skipRangeMax'] = Ensure::int($this->attributes['skipRangeMax']);
        }
        return $options;
    }
}
