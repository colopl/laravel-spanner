<?php

declare(strict_types=1);

namespace Colopl\Spanner\Support;

class Ensure
{
    /**
     * @param mixed $value
     * @return string
     */
    public static function string(mixed $value): string
    {
        assert(is_string($value));
        return $value;
    }

    public static function int(mixed $value): int
    {
        assert(is_int($value));
        return $value;
    }
}