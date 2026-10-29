<?php

declare(strict_types=1);

use App\Landing\Faq;
use App\Landing\UseCases;
use App\Models\User;
use App\Support\PublicUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Support/billing.php';
require_once __DIR__.'/../Support/landing.php';

uses(RefreshDatabase::class);

/*
 * The home page, which is the sales page. On an instance that sells nothing it says nothing about plans, prices or payment, anywhere:
 * not in the text, the links, the head or the structured data. On one that sells a plan it shows what the configuration says (the
 * amounts as the language writes them), and the pricing page is linked.
 */

/** The two languages the home page is read in, by the header the browser sends. */
const LANDING_LANGUAGES = ['en', 'fr'];

it('serves the home page', function (): void {
    $this->get(route('home'))->assertOk();
});

describe('on an instance that sells nothing', function (): void {
    beforeEach(fn () => billingOff());

    it('presents the application, in both languages, with one h1 and a heading for each part', function (string $language, string $title): void {
        $response = $this->get(route('home'), ['Accept-Language' => $language])->assertOk();
        $page = templateDom($response);

        expect(templateTexts($page, '//h1'))->toBe([$title]);
        expect(templateAttributes($page, '//html', 'lang'))->toBe([$language]);

        // The parts, each under an h2 (the pricing is the sixth, on an instance that sells a plan); the h3 sit under them.
        expect(count(templateTexts($page, '//h2')))->toBe(5);
    })->with([
        'English' => ['en', 'Your inventory, in the cloud.'],
        'French' => ['fr', 'Votre inventaire, dans le cloud.'],
    ]);

    it('says nothing about a price, a plan or a payment, anywhere in the page', function (string $language): void {
        $response = $this->get(route('home'), ['Accept-Language' => $language])->assertOk();
        $html = landingMarkup($response);

        expect($html)->not->toContain('Stripe')
            ->not->toContain('€')
            ->not->toContain('&euro;')
            ->not->toMatch('/\bPro\b/')
            ->not->toMatch('/\bTarifs?\b/')
            ->not->toMatch('/\bPricing\b/i')
            ->not->toContain('/pricing')
            ->not->toContain('pricing-period');
        expect(landingText($response))->not->toMatch('/\bPro\b|Stripe|€/');
    })->with(LANDING_LANGUAGES);

    it('describes itself as free and open source to search engines, with one free offer', function (): void {
        $response = $this->get(route('home'), ['Accept-Language' => 'en'])->assertOk();
        $application = landingJsonLdOf($response, 'SoftwareApplication');

        expect($application)->toMatchArray([
            'name' => config('app.name'),
            'url' => 'http://localhost/',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'inLanguage' => 'en',
            'isAccessibleForFree' => true,
            'license' => 'https://www.gnu.org/licenses/agpl-3.0.html',
        ]);
        expect($application['offers'])->toMatchArray(['@type' => 'Offer', 'price' => '0']);
        expect($application['offers'])->not->toHaveKey('priceSpecification');

        $description = templateAttributes(templateDom($response), '//head/meta[@name="description"]', 'content')[0];
        expect($description)->toEndWith('Free and open source.')->not->toContain('Pro');
        expect(templateTexts(templateDom($response), '//head/title'))->toBe(['Free online inventory software · '.config('app.name')]);
    });

    it('has no pricing link in the header or the footer, and no pricing page', function (): void {
        $page = templateDom($this->get(route('home'))->assertOk());

        expect(templateAttributes($page, '//a[contains(@href, "pricing")]', 'href'))->toBe([]);
        $this->get('/pricing')->assertNotFound();
        $this->get('/en/pricing')->assertNotFound();
    });

    it('does not mention what the instance cannot do: no signed export without Documenso', function (): void {
        config(['services.documenso.url' => null, 'services.documenso.api_key' => null]);

        $response = $this->get(route('home'), ['Accept-Language' => 'en'])->assertOk();

        expect(landingText($response))->not->toContain('signed')->not->toContain('Signed')->not->toContain('signature');
        expect(landingJsonLdOf($response, 'FAQPage')['mainEntity'])->toHaveCount(6);
    });

    it('offers a signed export where Documenso is set up', function (): void {
        config(['services.documenso.url' => 'https://sign.example', 'services.documenso.api_key' => 'api_test']);

        $response = $this->get(route('home'), ['Accept-Language' => 'en'])->assertOk();

        expect(landingText($response))->toContain('Typed fields, barcode scanning, spreadsheet import and signed exports.')
            ->toContain('What is a signed export?')
            ->toContain('Freeze a signed copy');
        expect(landingJsonLdOf($response, 'FAQPage')['mainEntity'])->toHaveCount(7);
    });
});

describe('the actions', function (): void {
    it('lead a visitor to the sign-in page, and a signed-in person to their space', function (): void {
        billingOff();

        $guest = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());
        $start = templateAttributes($guest, '//a[normalize-space()="Start for free"]', 'href');

        // The hero and the last word.
        expect($start)->toBe(['/login', '/login']);

        $person = User::factory()->create();
        $member = templateDom($this->actingAs($person)->get(route('home'), ['Accept-Language' => 'en'])->assertOk());

        expect(templateAttributes($member, '//a[normalize-space()="Open my space"]', 'href'))->toContain('/app')
            ->and(templateTexts($member, '//a[normalize-space()="Start for free"]'))->toBe([]);
    });

    it('lead to the templates and the source code', function (): void {
        billingOff();

        $page = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());

        expect(templateAttributes($page, '//a[normalize-space()="Browse the templates"]', 'href'))->toContain('/en/templates');
        expect(templateAttributes($page, '//section[@id="open-source"]//a[normalize-space()="Source code"]', 'href'))->toBe([rtrim(config()->string('app.repository_url'), '/')]);
    });
});

describe('the use cases', function (): void {
    it('are the templates of the catalogue, with their own columns and example rows', function (): void {
        billingOff();

        // The lines under the names are translated as the application speaks: English, like the request below.
        app()->setLocale('en');
        $panels = app(UseCases::class)->panels('en');

        expect(array_column($panels, 'slug'))->toBe(UseCases::SLUGS);

        $response = $this->get(route('home'), ['Accept-Language' => 'en'])->assertOk();
        $page = templateDom($response);

        // A button and a panel for each, the first one shown and the others hidden until the script shows them.
        expect($page->query('//button[@data-index]')->length)->toBe(count($panels))
            ->and($page->query('//div[@role="region"][@data-index]')->length)->toBe(count($panels));

        foreach ($panels as $index => $panel) {
            expect($page->query('//div[@role="region"][@data-index="'.$index.'"][@hidden]')->length)->toBe($index === 0 ? 0 : 1);
            expect(landingText($response))->toContain($panel['name'])->toContain($panel['hook']);
        }

        expect(templateAttributes($page, '//section[@id="use-cases"]//a[normalize-space()="Use this template"]', 'href'))
            ->toBe(array_map(fn (array $panel): string => PublicUrls::templatePath($panel['slug'], 'en'), $panels));
    });

    it('show the columns and the values of the template in the language of the page', function (): void {
        billingOff();

        $english = landingText($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());
        $french = landingText($this->get(route('home'), ['Accept-Language' => 'fr'])->assertOk());

        expect($english)->toContain('Shop stock')->toContain('Wine cellar');
        expect($french)->toContain('Stock de boutique')->toContain('Cave à vin');
    });

    it('leave out a template the catalogue does not have, and the whole part when none is left', function (): void {
        billingOff();
        templateFixtures();

        $response = $this->get(route('home'), ['Accept-Language' => 'en'])->assertOk();

        expect(templateAttributes(templateDom($response), '//section[@id="use-cases"]', 'id'))->toBe([]);
        expect(templateTexts(templateDom($response), '//h1'))->toBe(['Your inventory, in the cloud.']);
    });
});

describe('the questions', function (): void {
    it('are the ones the structured data tells search engines about, word for word', function (string $language): void {
        billingOff();

        $response = $this->get(route('home'), ['Accept-Language' => $language])->assertOk();
        $page = templateDom($response);
        $entities = landingJsonLdOf($response, 'FAQPage')['mainEntity'];

        $questions = templateTexts($page, '//section[@id="faq"]//button[contains(@class, "accordion-button")]');
        $answers = templateTexts($page, '//section[@id="faq"]//div[contains(@class, "accordion-body")]');

        expect($questions)->toBe(array_map(fn (array $entity): string => landingSqueeze($entity['name']), $entities));
        expect($answers)->toBe(array_map(fn (array $entity): string => landingSqueeze($entity['acceptedAnswer']['text']), $entities));
    })->with(LANDING_LANGUAGES);

    it('open the first answer and keep the others closed, with the states the collapse needs', function (): void {
        billingOff();

        $page = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());
        $expanded = templateAttributes($page, '//section[@id="faq"]//button[@data-bs-toggle="collapse"]', 'aria-expanded');

        expect($expanded[0])->toBe('true')->and(array_unique(array_slice($expanded, 1)))->toBe(['false']);
        expect(templateAttributes($page, '//section[@id="faq"]//button', 'aria-controls'))
            ->toBe(array_map(fn (string $target): string => ltrim($target, '#'), templateAttributes($page, '//section[@id="faq"]//button', 'data-bs-target')));
    });
});

describe('on an instance that sells a plan', function (): void {
    it('shows the pricing section with the amounts as each language writes them', function (): void {
        billingOn();

        $english = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());
        $french = templateDom($this->get(route('home'), ['Accept-Language' => 'fr'])->assertOk());

        expect(templateTexts($english, '//section[@id="pricing"]//h2'))->toBe(['Free to start, Pro to grow']);
        expect(templateTexts($english, '//section[@id="pricing"]//*[contains(@class, "landing-price-amount")]'))->toBe(['€0', '€6', '€60']);
        expect(templateTexts($french, '//section[@id="pricing"]//h2'))->toBe(['Gratuit pour démarrer, Pro pour grandir']);
        expect(templateTexts($french, '//section[@id="pricing"]//*[contains(@class, "landing-price-amount")]'))->toBe(['0 €', '6 €', '60 €']);
    });

    it('takes the amounts from the configuration, cents included, and writes nothing of its own', function (): void {
        billingOn();
        config(['billing.amounts.month' => 1234, 'billing.amounts.year' => 12999, 'billing.currency' => 'usd']);

        $english = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());
        $french = templateDom($this->get(route('home'), ['Accept-Language' => 'fr'])->assertOk());

        expect(templateTexts($english, '//section[@id="pricing"]//*[contains(@class, "landing-price-amount")]'))->toBe(['$0', '$12.34', '$129.99']);
        expect(templateTexts($french, '//section[@id="pricing"]//*[contains(@class, "landing-price-amount")]'))->toBe(['0 $US', '12,34 $US', '129,99 $US']);
        // 12 x 12.34 = 148.08, the year costs 129.99.
        expect(templateTexts($english, '//section[@id="pricing"]//*[contains(@class, "landing-toggle-saving")]'))->toBe(['Save $18.09 a year']);
    });

    it('links the Pro button to the billing page, the period following the switch, and the plans to the pricing page', function (): void {
        billingOn();

        $page = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());
        $pro = $page->query('//section[@id="pricing"]//a[normalize-space()="Choose Pro"]')->item(0);

        expect($pro)->toBeInstanceOf(DOMElement::class)
            ->and($pro->getAttribute('href'))->toBe('/app/billing?interval=month');

        $plans = $page->query('//section[@id="pricing"]//*[@data-href-month]')->item(0);

        expect($plans)->toBeInstanceOf(DOMElement::class)
            ->and($plans->getAttribute('data-href-month'))->toBe('/app/billing?interval=month')
            ->and($plans->getAttribute('data-href-year'))->toBe('/app/billing?interval=year')
            ->and($plans->getAttribute('x-data'))->toBe('pricingPeriod');

        expect(templateAttributes($page, '//section[@id="pricing"]//a[normalize-space()="Compare the plans in detail"]', 'href'))->toBe(['/en/pricing']);
        expect(templateAttributes($page, '//section[@id="pricing"]//a[normalize-space()="Start for free"]', 'href'))->toBe(['/login']);
    });

    it('shows no switch and no yearly price when the instance has no yearly Price', function (): void {
        billingOn(['services.stripe.prices.yearly' => null]);

        $response = $this->get(route('home'), ['Accept-Language' => 'en'])->assertOk();
        $page = templateDom($response);

        expect(templateTexts($page, '//section[@id="pricing"]//*[contains(@class, "landing-price-amount")]'))->toBe(['€0', '€6']);
        expect($page->query('//input[@name="pricing-period"]')->length)->toBe(0)
            ->and($page->query('//*[@x-data="pricingPeriod"]')->length)->toBe(0);
        expect(landingText($response))->not->toContain('a year')->not->toContain('Yearly');
        expect(landingJsonLdOf($response, 'SoftwareApplication')['offers'])->toHaveCount(2);
    });

    it('tells search engines what each plan costs, in a decimal that does not depend on the language', function (): void {
        billingOn();
        config(['billing.amounts.month' => 650, 'billing.amounts.year' => 6000]);

        foreach (LANDING_LANGUAGES as $language) {
            $offers = landingJsonLdOf($this->get(route('home'), ['Accept-Language' => $language])->assertOk(), 'SoftwareApplication')['offers'];

            expect($offers)->toHaveCount(3)
                ->and($offers[0])->toMatchArray(['price' => '0', 'priceCurrency' => 'EUR'])
                ->and($offers[1]['priceSpecification'])->toMatchArray(['price' => '6.50', 'priceCurrency' => 'EUR', 'billingDuration' => 'P1M'])
                ->and($offers[2]['priceSpecification'])->toMatchArray(['price' => '60.00', 'priceCurrency' => 'EUR', 'billingDuration' => 'P1Y']);
        }
    });

    it('adds the pricing page to the header and the footer', function (): void {
        billingOn();

        $english = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());
        $french = templateDom($this->get(route('home'), ['Accept-Language' => 'fr'])->assertOk());

        expect(templateAttributes($english, '//header//a[normalize-space()="Pricing"]', 'href'))->toBe(['/en/pricing']);
        expect(templateAttributes($english, '//footer//a[normalize-space()="Pricing"]', 'href'))->toBe(['/en/pricing']);
        expect(templateAttributes($french, '//header//a[normalize-space()="Tarifs"]', 'href'))->toBe(['/pricing']);
        expect(templateAttributes($french, '//footer//a[normalize-space()="Tarifs"]', 'href'))->toBe(['/pricing']);
    });

    it('speaks of plans in the first sentence, and of Pro among the questions', function (): void {
        billingOn();

        $text = landingText($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());

        expect($text)->toContain('Free to start, Pro when you outgrow it.')
            ->toContain('You can use it free within the limits of the Free plan: 3 inventories of 500 items each. Pro lifts them.');
    });

    it('describes its offers in the description for search engines', function (): void {
        billingOn();

        $page = templateDom($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());

        expect(templateAttributes($page, '//head/meta[@name="description"]', 'content')[0])->toEndWith('Free plan, open source.');
    });

    it('stays one page of one h1', function (): void {
        billingOn();

        $page = templateDom($this->get(route('home'), ['Accept-Language' => 'fr'])->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['Votre inventaire, dans le cloud.']);
    });
});

describe('the questions about plans', function (): void {
    it('count the limits the instance sets, and nothing else', function (): void {
        billingOn();
        config(['billing.plans.free.limits.inventories' => 1, 'billing.plans.free.limits.items' => 1234]);

        $text = landingText($this->get(route('home'), ['Accept-Language' => 'en'])->assertOk());

        expect($text)->toContain('within the limits of the Free plan: 1 inventory of 1,234 items each.');
        expect(Faq::home(null)[0]['answer'])->toBe('Yes. This instance is free to use and the code is open source (AGPL-3.0).');
    });
});
