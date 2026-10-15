<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;

/**
 * A percentage: a rate, a discount, a share. The value is the number before the % sign ("12.5" for 12.5 %), with the
 * decimals the field allows (0 to 4, two by default).
 */
final class PercentageType extends DecimalType
{
    /** Most decimals a percentage may have. */
    public const MAX_DECIMALS = 4;

    /** The decimals of a field that does not say. */
    public const DEFAULT_DECIMALS = 2;

    /**
     * The word that identifies the type in the API and in the database: `percentage`.
     */
    public function key(): string
    {
        return 'percentage';
    }

    /**
     * What the type is called for a person ("Percentage"), in the language of the request.
     */
    public function label(): string
    {
        return __('Percentage');
    }

    /**
     * The decimals.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
    {
        return [
            'decimals' => ['nullable', 'integer', 'between:0,'.self::MAX_DECIMALS],
        ];
    }

    /**
     * Fills in the decimals.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        return ['decimals' => (int) ($options['decimals'] ?? self::DEFAULT_DECIMALS)];
    }

    protected function decimals(InventoryField $field): int
    {
        return (int) $field->option('decimals', self::DEFAULT_DECIMALS);
    }
}
