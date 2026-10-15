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

    public function key(): string
    {
        return 'percentage';
    }

    public function label(): string
    {
        return __('Percentage');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function optionRules(): array
    {
        return [
            'decimals' => ['nullable', 'integer', 'between:0,'.self::MAX_DECIMALS],
        ];
    }

    /**
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
