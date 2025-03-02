<?php

namespace Colopl\Spanner\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use JsonSerializable;

/**
 * @implements CastsAttributes<array|null, array>
 */
class SpannerJson implements CastsAttributes
{
    public function get($model, $key, $value, $attributes): array|null
    {
        if ($value === null) {
            return null;
        }

        if(is_array($value)) {
            return $value;
        }

        if(!is_string($value)) {
            throw new \InvalidArgumentException('The given value must be an array, string or null.');
        }

        return json_decode($value, true);
    }

    public function set($model, $key, $value, $attributes): array
    {
        if (!is_array($value) && !$value instanceof JsonSerializable && $value !== null && !is_string($value)) {
            throw new \InvalidArgumentException('The given value must be an array, JsonSerializable, string or null.');
        }

        return [$key => new SpannerJsonType($value)];
    }
}
