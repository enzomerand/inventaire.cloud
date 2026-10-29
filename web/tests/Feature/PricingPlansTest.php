<?php

declare(strict_types=1);

use App\Landing\PricingPlans;

/*
 * What the plans of an instance write: an amount of money as the page writes it (whole numbers of the smallest unit of the currency, with
 * the cents only when there are some, and the sign where the language puts it, with a no-break space so that a price never wraps between
 * its number and its sign), and the decimal text of a price for structured data.
 */

/**
 * The plans of an instance that sells in a currency. Only the currency matters to the amounts, so the rest is a plain Pro at 6 a month.
 */
function pricingPlansIn(string $currency): PricingPlans
{
    return new PricingPlans(
        currency: $currency,
        month: 600,
        year: null,
        limits: ['free' => [], 'pro' => []],
        photoEntry: ['free' => false, 'pro' => false],
        photoEntryOffered: false,
        signatures: false,
        automaticTax: false,
    );
}

it('writes an amount the way each language does, without the cents when there are none', function (int $cents, string $language, string $expected): void {
    app()->setLocale($language);

    expect(pricingPlansIn('eur')->price($cents))->toBe($expected);
})->with([
    'French, whole' => [600, 'fr', "6\u{00A0}€"],
    'French, cents' => [650, 'fr', "6,50\u{00A0}€"],
    'French, thousands' => [120000, 'fr', "1\u{202F}200\u{00A0}€"],
    'English, whole' => [600, 'en', '€6'],
    'English, cents' => [650, 'en', '€6.50'],
    'English, thousands' => [120000, 'en', '€1,200'],
    'zero' => [0, 'en', '€0'],
    'one cent' => [1, 'en', '€0.01'],
]);

it('takes the currency in any case and writes the symbol of the one it is given', function (): void {
    app()->setLocale('en');

    expect(pricingPlansIn('GBP')->price(1250))->toBe('£12.50')
        ->and(pricingPlansIn('gbp')->price(1250))->toBe('£12.50')
        ->and(pricingPlansIn('usd')->price(1250))->toBe('$12.50');
});

it('writes the price for structured data as a plain decimal, whatever the language', function (int $cents, string $expected): void {
    expect(PricingPlans::decimal($cents))->toBe($expected);
})->with([
    'whole' => [600, '6.00'],
    'cents' => [650, '6.50'],
    'under one' => [5, '0.05'],
    'thousands' => [120000, '1200.00'],
]);
