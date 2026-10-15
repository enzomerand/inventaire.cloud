<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use Closure;

/**
 * An amount of money in one currency: a price, a value. The currency is an option (an ISO 4217 code, the euro by
 * default) and gives the decimals of the amounts (2 for the euro, 0 for the yen), so "12.5" is stored as "12.50".
 *
 * A new field can only be given a currency that is in use somewhere today ({@see Currencies::exists()}). One that
 * already has a currency which went out of use keeps it: it is accepted again when the field is saved with it.
 */
final class MoneyType extends DecimalType
{
    /** The currency of a field that does not say. */
    public const DEFAULT_CURRENCY = 'EUR';

    /**
     * The word that identifies the type in the API and in the database: `money`.
     */
    public function key(): string
    {
        return 'money';
    }

    /**
     * What the type is called for a person ("Amount"), in the language of the request.
     */
    public function label(): string
    {
        return __('Amount');
    }

    /**
     * The currency: the code of one in use somewhere, or the one the field already has.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
    {
        return [
            'currency' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) use ($previous): void {
                // A currency the field already has stays acceptable even when it went out of use: saving the field
                // again, with the currency it was created with, is not a reason to refuse it.
                if (! is_string($value) || (! Currencies::exists($value) && $value !== ($previous['currency'] ?? null))) {
                    $fail(__('The :attribute must be the code of a currency, such as EUR or USD.'));
                }
            }],
        ];
    }

    /**
     * Fills in the currency.
     *
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
