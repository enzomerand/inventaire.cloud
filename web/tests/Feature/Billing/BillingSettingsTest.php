<?php

declare(strict_types=1);

use App\Billing\Billing;

require_once __DIR__.'/../../Support/billing.php';

/*
 * What the instance is configured to sell: billing is on only with a secret key and the monthly Price, the yearly Price and the webhook
 * secret are optional, and the values are read each time.
 */

describe('whether billing is on', function (): void {
    it('is off on an instance that set nothing, which is how a self-hosted one starts', function (): void {
        billingOff();

        expect(Billing::enabled())->toBeFalse()
            ->and(Billing::webhookEnabled())->toBeFalse()
            ->and(Billing::intervals())->toBe([]);
    });

    it('needs the secret key and the monthly Price, both', function (): void {
        billingOff();
        config(['services.stripe.secret' => 'sk_test_x']);
        expect(Billing::enabled())->toBeFalse();

        config(['services.stripe.secret' => null, 'services.stripe.prices.monthly' => 'price_m']);
        expect(Billing::enabled())->toBeFalse();

        config(['services.stripe.secret' => 'sk_test_x']);
        expect(Billing::enabled())->toBeTrue();
    });

    it('does not take blanks for a setting', function (): void {
        config(['services.stripe.secret' => '  ', 'services.stripe.prices.monthly' => 'price_m']);

        expect(Billing::enabled())->toBeFalse();

        config(['services.stripe.secret' => ' sk_test_x ']);

        expect(Billing::enabled())->toBeTrue()->and(Billing::secret())->toBe('sk_test_x');
    });

    it('opens the webhook only with billing on and a signing secret', function (): void {
        billingOn(['services.stripe.webhook_secret' => null]);
        expect(Billing::webhookEnabled())->toBeFalse();

        config(['services.stripe.webhook_secret' => 'whsec_x']);
        expect(Billing::webhookEnabled())->toBeTrue()->and(Billing::webhookSecret())->toBe('whsec_x');

        config(['services.stripe.secret' => null]);
        expect(Billing::webhookEnabled())->toBeFalse();
    });
});

describe('the Prices', function (): void {
    it('offers the month, and the year only when a yearly Price is set', function (): void {
        billingOn(['services.stripe.prices.yearly' => null]);

        expect(Billing::intervals())->toBe(['month'])
            ->and(Billing::priceFor('month'))->toBe('price_monthly_test')
            ->and(Billing::priceFor('year'))->toBeNull();

        config(['services.stripe.prices.yearly' => 'price_y']);

        expect(Billing::intervals())->toBe(['month', 'year'])
            ->and(Billing::priceFor('year'))->toBe('price_y');
    });

    it('knows no other interval', function (): void {
        billingOn();

        expect(Billing::priceFor('week'))->toBeNull()
            ->and(Billing::priceFor(''))->toBeNull();
    });

    it('shows what Pro costs, in cents, with the year only when it can be bought', function (): void {
        billingOn();

        expect(Billing::display())->toBe(['currency' => 'eur', 'month' => 600, 'year' => 6000]);

        config(['services.stripe.prices.yearly' => null, 'billing.amounts.month' => 900, 'billing.currency' => 'usd']);

        expect(Billing::display())->toBe(['currency' => 'usd', 'month' => 900, 'year' => null]);
    });
});

describe('the rest of the settings', function (): void {
    it('speaks the version of the API the code is written for, to Stripe', function (): void {
        expect(Billing::apiVersion())->toBe('2026-09-30.endive')
            ->and(Billing::baseUrl())->toBe('https://api.stripe.com');
    });

    it('leaves the tax to the Stripe account unless it is asked to work it out', function (): void {
        expect(Billing::automaticTax())->toBeFalse();

        config(['billing.automatic_tax' => true]);

        expect(Billing::automaticTax())->toBeTrue()
            ->and(Billing::integrationIdentifier())->toBe('inventaire-cloud-pkqzrvmw');
    });
});
