<?php

namespace Colopl\Spanner\Casts;

use Google\Cloud\Spanner\PgJsonb;
use Google\Cloud\Spanner\V1\TypeAnnotationCode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/** 
 * @implements CastsAttributes<mixed, mixed> 
 * */
class SpannerJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('The given value must be an array, string or null.');
        }

        return json_decode($value, true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        if (
            !is_array($value) && 
            !$value instanceof JsonSerializable && 
            $value !== null && 
            !is_string($value)
        ) {
            throw new \InvalidArgumentException('The given value must be an array, JsonSerializable, string or null.');
        }

        return [$key => new SpannerJsonType($value)];
    }
}


class SpannerJsonType extends PgJsonb
{
    public function typeAnnotation(): int
    {
        return TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED;
    }
}
