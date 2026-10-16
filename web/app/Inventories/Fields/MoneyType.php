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
 *
 * The decimals are read from ICU **once**, when the field is created and whenever its currency changes, and kept in
 * the options of the field (`decimals`; not an option a person can send). Every amount is then judged and written with
 * the decimals the field has stored, so that an update of the ICU data of the server (a currency that changes its
 * minor unit) cannot make the amounts already stored fail, or stored amounts disagree with each other. A field stored
 * without them (written before they were kept) reads them from ICU until its options are next saved.
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
     * Amounts are added up.
     */
    public function summable(): bool
    {
        return true;
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
     * Fills in the currency, and freezes its decimals: the ones the field already has when the currency stays the same,
     * the ones ICU gives for the currency when the field is created or the currency changes.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        $sent = $options['currency'] ?? null;
        $currency = is_string($sent) && $sent !== '' ? $sent : self::DEFAULT_CURRENCY;
        $frozen = $previous['decimals'] ?? null;
        $decimals = ($previous['currency'] ?? null) === $currency && is_int($frozen) ? $frozen : Currencies::fractionDigits($currency);

        return ['currency' => $currency, 'decimals' => $decimals];
    }

    /**
     * The decimals stored with the field; ICU's for the currency only when the field has none stored.
     */
    public function decimals(InventoryField $field): int
    {
        $stored = $field->option('decimals');

        return is_int($stored) ? $stored : Currencies::fractionDigits((string) $field->option('currency', self::DEFAULT_CURRENCY));
    }
}
