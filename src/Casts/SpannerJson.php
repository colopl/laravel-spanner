<?php

namespace Colopl\Spanner\Casts;

use Google\Cloud\Spanner\PgJsonb;
use Google\Cloud\Spanner\V1\TypeAnnotationCode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class SpannerJson implements CastsAttributes
{
	public function get($model, $key, $value, $attributes)
	{
		return json_decode((string) $value, true);
	}

	public function set($model, $key, $value, $attributes)
	{
		return [$key => new SpannerJsonType($value)];
	}
}

class SpannerJsonType extends PgJsonb
{
	public function typeAnnotation()
	{
		return TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED;
	}
}
