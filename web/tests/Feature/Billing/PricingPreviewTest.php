<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\WorkspacePlan;
use App\Landing\PricingPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/landing.php';

uses(RefreshDatabase::class);

/*
 * The three states of an instance as far as plans go: it sells (billing on and the identity of the seller complete), it previews its plans
 * (BILLING_PRICING_PREVIEW: pages with VAT-included prices and a Pro button that cannot be used, no checkout), or it does neither and
 * shows nothing. The VAT-included mention ("TTC") is next to every Pro amount whenever the plans are shown.
 */

/**
 * Makes the instance one of the states the table of {@see PricingPlans} knows.
 *
 * @param  string  $state  `neither`, `preview`, `stripe, complete`, `stripe, incomplete`, `stripe, incomplete, preview`, `stripe, complete, preview`.
 */
function pricingState(string $state): void
{
    billingOff();

    if (str_contains($state, 'stripe')) {
        billingOn();

        if (str_contains($state, 'incomplete')) {
            billingSeller('vat_number');
        }
    }

    if (str_contains($state, 'preview')) {
        config(['billing.pricing_preview' => true]);
    }
}

describe('what the instance does with its plans', function (): void {
    it('sells, previews or shows nothing', function (string $state, bool $selling, bool $shown): void {
        pricingState($state);

        expect(PricingPlans::selling())->toBe($selling)
            ->and(PricingPlans::shown())->toBe($shown);
    })->with([
        'neither' => ['neither', false, false],
        'a preview without Stripe' => ['preview', false, true],
        'Stripe on and the seller complete' => ['stripe, complete', true, true],
        'Stripe on and the seller incomplete' => ['stripe, incomplete', false, false],
        'Stripe on, the seller incomplete and a preview' => ['stripe, incomplete, preview', false, true],
        'Stripe on, the seller complete and a preview: it sells' => ['stripe, complete, preview', true, true],
    ]);

    it('shows nothing and sells nothing without an amount to show', function (): void {
        billingOn();
        config(['billing.pricing_preview' => true, 'billing.amounts.month' => 0]);

        expect(PricingPlans::selling())->toBeFalse()->and(PricingPlans::shown())->toBeFalse();
    });

    it('keeps the quotas and the plans of a workspace whose seller became incomplete: only the sale stops', function (): void {
        billingOn();
        $workspace = billingWorkspace('active');
        billingSeller('vat_number');

        expect(Billing::enabled())->toBeTrue()
            ->and(PricingPlans::selling())->toBeFalse()
            ->and(WorkspacePlan::for($workspace)->value)->toBe('pro');
    });
});

describe('an instance that previews its plans without Stripe', function (): void {
    beforeEach(function (): void {
        Http::fake();
        Http::preventStrayRequests();
        pricingState('preview');
    });

    it('shows the pricing page with the amounts VAT-included, as each language writes them', function (): void {
        $english = templateDom($this->get('/en/pricing')->assertOk());
        $french = templateDom($this->get('/pricing')->assertOk());

        expect(templateTexts($english, '//*[contains(@class, "landing-price-amount")]'))->toBe(['€0', '€6', '€60'])
            ->and(templateTexts($english, '//p[contains(@class, "landing-price-line")]'))->toBe(['€6 incl. VAT / month', '€60 incl. VAT / year'])
            ->and(templateTexts($english, '//*[contains(@class, "landing-price-alt")]'))->toBe(['or €60 incl. VAT a year'])
            ->and(templateTexts($french, '//p[contains(@class, "landing-price-line")]'))->toBe(['6 € TTC / mois', '60 € TTC / an'])
            ->and(templateTexts($french, '//*[contains(@class, "landing-price-note") and contains(@class, "landing-only-year")]'))->toBe(['Soit 5 € TTC par mois'])
            ->and(landingText($this->get('/pricing')->assertOk()))->toContain('Les prix s\'entendent TTC.');
    });

    it('shows the yearly amount of the configuration, and none when it is zero', function (): void {
        config(['billing.amounts.year' => 0]);

        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateTexts($page, '//p[contains(@class, "landing-price-line")]'))->toBe(['€6 incl. VAT / month'])
            ->and($page->query('//input[@name="pricing-period"]')->length)->toBe(0);
    });

    it('has a Pro button that cannot be used: disabled, with no link, and nothing that leads to a payment', function (): void {
        $response = $this->get('/en/pricing')->assertOk();
        $page = templateDom($response);
        $html = landingMarkup($response);

        expect(templateTexts($page, '//article[@aria-labelledby="plan-pro"]//button[@disabled]'))->toBe(['Coming soon'])
            ->and($page->query('//article[@aria-labelledby="plan-pro"]//a')->length)->toBe(0)
            ->and($page->query('//*[@data-href-month or @data-href-year]')->length)->toBe(0)
            ->and($html)->not->toContain('/app/billing')->not->toContain('Choose Pro')->not->toContain('Stripe')->not->toContain('Cancel anytime');

        expect(templateTexts(templateDom($this->get('/pricing')->assertOk()), '//article[@aria-labelledby="plan-pro"]//button[@disabled]'))->toBe(['Bientôt disponible']);
    });

    it('keeps the switch between the monthly and the yearly price', function (): void {
        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateAttributes($page, '//input[@name="pricing-period"]', 'value'))->toBe(['month', 'year'])
            ->and($page->query('//*[@x-data="pricingPeriod"]')->length)->toBe(1);
    });

    it('promises no purchase in its questions: not yet, and the VAT', function (): void {
        $response = $this->get('/en/pricing')->assertOk();
        $page = templateDom($response);
        $text = landingText($response);

        expect(templateTexts($page, '//section[@id="billing-faq"]//button[contains(@class, "accordion-button")]'))->toBe(['Can I subscribe to Pro today?', 'What about VAT?'])
            ->and($text)->toContain('Not yet. Pro is not on sale for now')
            ->not->toContain('How do I pay?')->not->toContain('Can I cancel anytime?')->not->toContain('customer portal');

        $entities = landingJsonLdOf($response, 'FAQPage')['mainEntity'];
        expect(array_column($entities, 'name'))->toBe(['Can I subscribe to Pro today?', 'What about VAT?']);
    });

    it('offers nothing to buy to search engines: no offer in the structured data, no page in the sitemap', function (): void {
        config(['app.indexable' => true]);

        foreach (['/pricing', '/en/pricing', '/'] as $path) {
            $response = $this->get($path)->assertOk();

            expect(landingJsonLdOf($response, 'SoftwareApplication'))->not->toHaveKey('offers')
                ->and(json_encode(landingJsonLd($response)))->not->toContain('priceCurrency');
        }

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

        expect($sitemap)->not->toContain('pricing')->not->toContain('terms');

        $description = templateAttributes(templateDom($this->get('/en/pricing')->assertOk()), '//head/meta[@name="description"]', 'content');
        expect($description)->toBe(['Compare the Free and Pro plans: inventories, items, storage, members and share links. Pro from €6 incl. VAT a month.']);
    });

    it('shows the pricing section on the home page, with the same disabled button, and links to the pricing page and the terms', function (): void {
        $response = $this->get('/', ['Accept-Language' => 'en'])->assertOk();
        $page = templateDom($response);

        expect(templateTexts($page, '//section[@id="pricing"]//p[contains(@class, "landing-price-line")]'))->toBe(['€6 incl. VAT / month', '€60 incl. VAT / year'])
            ->and(templateTexts($page, '//section[@id="pricing"]//button[@disabled]'))->toBe(['Coming soon'])
            ->and(templateAttributes($page, '//section[@id="pricing"]//a[normalize-space()="Compare the plans in detail"]', 'href'))->toBe(['/en/pricing'])
            ->and(templateAttributes($page, '//section[@id="pricing"]//a[normalize-space()="Terms of sale"]', 'href'))->toBe(['/en/terms'])
            ->and(templateAttributes($page, '//header//a[normalize-space()="Pricing"]', 'href'))->toBe(['/en/pricing'])
            ->and(templateAttributes($page, '//footer//a[normalize-space()="Pricing"]', 'href'))->toBe(['/en/pricing'])
            ->and(landingMarkup($response))->not->toContain('Stripe')->not->toContain('/app/billing');
    });

    it('answers a checkout with the refusal of an instance that sells nothing, and calls nobody', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_disabled');

        Http::assertNothingSent();
    });
});

describe('an instance with Stripe on and an incomplete seller', function (): void {
    it('sells nothing and shows nothing unless it previews: the pages are a 404, as if there were no plan', function (string $key): void {
        $stripe = billingOn();
        billingSeller($key);

        expect(PricingPlans::selling())->toBeFalse();

        foreach (['/pricing', '/en/pricing', '/terms', '/en/terms'] as $path) {
            $this->get($path)->assertNotFound();
        }

        $home = landingMarkup($this->get('/', ['Accept-Language' => 'en'])->assertOk());

        expect($home)->not->toContain('€')->not->toContain('/pricing')->not->toContain('/terms')->not->toContain('Stripe');
        expect($stripe->calls)->toBe([]);
    })->with(['vat_number', 'registration', 'mediator_url', 'phone']);

    it('refuses a checkout with the answer of an instance that sells nothing, without a word to Stripe', function (): void {
        $stripe = billingOn();
        billingSeller('vat_number');
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'billing_disabled');

        expect($stripe->calls)->toBe([])->and($stripe->count('POST /v1/checkout/sessions'))->toBe(0);
    });

    it('previews its plans when asked to, and still opens no checkout', function (): void {
        $stripe = billingOn();
        billingSeller('vat_number');
        config(['billing.pricing_preview' => true, 'app.indexable' => true]);

        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateTexts($page, '//article[@aria-labelledby="plan-pro"]//button[@disabled]'))->toBe(['Coming soon'])
            ->and(templateTexts($page, '//p[contains(@class, "landing-price-line")]'))->toBe(['€6 incl. VAT / month', '€60 incl. VAT / year']);

        $terms = $this->get('/en/terms')->assertOk();

        expect(landingText($terms))->toContain('Working version: information to be completed')
            ->and(templateAttributes(templateDom($terms), '//head/meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow']);

        $team = inventoryTeam();
        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertStatus(409)->assertJsonPath('code', 'billing_disabled');
        expect($stripe->count('POST /v1/checkout/sessions'))->toBe(0);
    });
});

describe('an instance that sells', function (): void {
    beforeEach(function (): void {
        billingOn();
        config(['app.indexable' => true]);
    });

    it('has a Pro button that leads to the billing page, the amounts VAT-included, and a terms page without the banner', function (): void {
        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateAttributes($page, '//article[@aria-labelledby="plan-pro"]//a[contains(@class, "btn")]', 'href'))->toBe(['/app/billing?interval=month'])
            ->and($page->query('//article[@aria-labelledby="plan-pro"]//button[@disabled]')->length)->toBe(0)
            ->and(templateTexts($page, '//p[contains(@class, "landing-price-line")]'))->toBe(['€6 incl. VAT / month', '€60 incl. VAT / year'])
            ->and(templateAttributes($page, '//*[@data-href-month]', 'data-href-month'))->toBe(['/app/billing?interval=month']);

        $terms = $this->get('/en/terms')->assertOk();

        expect(landingText($terms))->not->toContain('Working version')
            ->and(templateAttributes(templateDom($terms), '//head/meta[@name="robots"]', 'content'))->toBe(['index, follow']);
    });

    it('tells search engines what each plan costs', function (): void {
        $offers = landingJsonLdOf($this->get('/en/pricing')->assertOk(), 'SoftwareApplication')['offers'];

        expect($offers)->toHaveCount(3)
            ->and($offers[1]['priceSpecification'])->toMatchArray(['price' => '6.00', 'billingDuration' => 'P1M', 'valueAddedTaxIncluded' => true])
            ->and($offers[2]['priceSpecification'])->toMatchArray(['price' => '60.00', 'billingDuration' => 'P1Y', 'valueAddedTaxIncluded' => true]);

        $description = templateAttributes(templateDom($this->get('/en/pricing')->assertOk()), '//head/meta[@name="description"]', 'content');
        expect($description)->toBe(['Compare the Free and Pro plans: inventories, items, storage, members and share links. Pro from €6 incl. VAT a month, cancel anytime.']);
    });

    it('opens a checkout', function (): void {
        $stripe = billingOn();
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', billingApiUri($team['workspace'], 'checkout'), ['interval' => 'month'])->assertOk();

        $session = array_values($stripe->sessions)[0] ?? [];

        expect($stripe->count('POST /v1/checkout/sessions'))->toBe(1)
            ->and($session['consent_collection'])->toBe(['terms_of_service' => 'required'])
            ->and($session['custom_text']['submit']['message'])->toBe('Order with obligation to pay')
            ->and($session['custom_text']['terms_of_service_acceptance']['message'])->toContain('(http://localhost/en/terms)')
            ->and($session)->not->toHaveKey('payment_method_types')
            ->and($session['automatic_tax'])->toBe(['enabled' => 'false']);
    });
});

describe('an instance that neither sells nor previews', function (): void {
    it('says nothing of VAT, terms or plans on the pages it has, in the markup or the structured data', function (string $path): void {
        billingOff();

        $response = $this->get($path)->assertOk();
        $html = landingMarkup($response);

        expect($html)->not->toContain('TTC')->not->toContain('incl. VAT')->not->toContain('/terms')->not->toContain('/pricing')
            ->not->toContain('Conditions générales')->not->toContain('Terms of sale')->not->toContain('Stripe')->not->toContain('€');
    })->with(['/', '/templates', '/en/templates']);
});
