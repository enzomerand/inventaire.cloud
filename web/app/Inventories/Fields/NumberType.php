<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\SingleLineText;
use Illuminate\Validation\ValidationException;

/**
 * A number: a quantity, a weight, a size. Options: the decimals it has (0 to 6, none by default), a minimum, a maximum
 * and a unit shown next to it ("kg"). Values are below 1e15 in absolute value.
 */
final class NumberType extends DecimalType
{
    /** Most decimals a number may have. */
    public const MAX_DECIMALS = 6;

    /** Longest unit, in characters. */
    public const UNIT_MAX_LENGTH = 16;

    /**
     * The word that identifies the type in the API and in the database: `number`.
     */
    public function key(): string
    {
        return 'number';
    }

    /**
     * What the type is called for a person ("Number"), in the language of the request.
     */
    public function label(): string
    {
        return __('Number');
    }

    /**
     * The decimals, the bounds and the unit.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
    {
        return [
            'decimals' => ['nullable', 'integer', 'between:0,'.self::MAX_DECIMALS],
            'min' => ['nullable', new DecimalRule(self::MAX_DECIMALS)],
            'max' => ['nullable', new DecimalRule(self::MAX_DECIMALS)],
            'unit' => ['nullable', 'string', 'max:'.self::UNIT_MAX_LENGTH, new SingleLineText],
        ];
    }

    /**
     * Fills in the decimals, writes the bounds as the shortest text that holds them, drops what is empty, and checks
     * that the minimum is not above the maximum.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        $min = Decimal::tryFrom($options['min'] ?? null);
        $max = Decimal::tryFrom($options['max'] ?? null);

        if ($min !== null && $max !== null && $min->compareTo($max) > 0) {
            throw ValidationException::withMessages(['options.max' => [__('The maximum must not be below the minimum.')]]);
        }

        $unit = is_string($options['unit'] ?? null) ? SingleLineText::trim($options['unit']) : '';

        return array_filter([
            'decimals' => (int) ($options['decimals'] ?? 0),
            'min' => $min?->format(),
            'max' => $max?->format(),
            'unit' => $unit === '' ? null : $unit,
        ], fn (mixed $option): bool => $option !== null);
    }

    protected function decimals(InventoryField $field): int
    {
        return (int) $field->option('decimals', 0);
    }
}
