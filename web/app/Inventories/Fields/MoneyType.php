<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * An amount of money in one currency: a price, a value. The currency is an option (an ISO 4217 code, the euro by
 * default) and gives the decimals of the amounts (2 for the euro, 0 for the yen), so "12.5" is stored as "12.50".
 */
final class MoneyType extends DecimalType
{
    /** The currency of a field that does not say. */
    public const DEFAULT_CURRENCY = 'EUR';

    public function key(): string
    {
        return 'money';
    }

    public function label(): string
    {
        return __('Amount');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function optionRules(): array
    {
        return [
            'currency' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Currencies::exists($value)) {
                    $fail(__('The :attribute must be the code of a currency, such as EUR or USD.'));
                }
            }],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        $currency = $options['currency'] ?? null;

        return ['currency' => is_string($currency) && $currency !== '' ? $currency : self::DEFAULT_CURRENCY];
    }

    protected function decimals(InventoryField $field): int
    {
        return Currencies::fractionDigits((string) $field->option('currency', self::DEFAULT_CURRENCY));
    }
}
