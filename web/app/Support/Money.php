<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Number;

/**
 * An amount of money as a person reads it, in the language of the page.
 *
 * The amounts of the application are whole numbers in the smallest unit of the currency (cents), the way Stripe and
 * `config/billing.php` hold them. The page shows them without the cents when there are none ("6 €", "€6") and with two
 * decimals otherwise ("6,50 €", "€6.50"), written the way the language writes a price: the sign before or after the
 * number, the decimal comma or point, and a no-break space where the language puts one, so that a price never wraps
 * between its number and its sign.
 */
final class Money
{
    /**
     * The amount in a currency, in a language.
     *
     * @param  int  $cents  The amount in the smallest unit of the currency.
     * @param  string  $currency  The ISO code of the currency, in any case ("eur").
     * @param  string  $locale  The language the amount is written in ("fr").
     */
    public static function display(int $cents, string $currency, string $locale): string
    {
        $formatted = Number::currency($cents / 100, strtoupper($currency), $locale, $cents % 100 === 0 ? 0 : 2);

        // The formatter refuses a currency it does not know: the code and the amount are still a price.
        return $formatted === false ? number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, '.', '').' '.strtoupper($currency) : $formatted;
    }
}
