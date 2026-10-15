<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * A column that holds a JSON object, read as an array and always written as an object.
 *
 * The framework's `array` cast writes an empty array as `[]`, a JSON list. A column of keyed values (the values of an
 * item, by field) must stay an object when it holds nothing, or the engines' JSON functions treat the empty value
 * differently from the filled one. Reading is forgiving: a missing, empty or unusable value is an empty array.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class JsonObject implements CastsAttributes
{
    /**
     * The decoded object, or an empty array when the column holds nothing usable.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The JSON text to store: an object, empty or not.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value === null || $value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR);
    }
}
