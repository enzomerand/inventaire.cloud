<?php

declare(strict_types=1);

use App\Landing\Faq;
use App\Landing\PricingPlans;
use App\Models\User;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Support/billing.php';
require_once __DIR__.'/../Support/landing.php';

uses(RefreshDatabase::class);

/*
 * The pricing page: the Free and Pro plans side by side, their comparison, and the questions about billing. It exists on an instance that
 * sells a plan only (a 404 elsewhere), once per language like the templates, and says nothing the configuration of the instance does not:
 * the amounts come from config/billing.php (Billing::display()), the limits from the plans (Plan), the features from what the instance can do.
 */

/**
 * The cells of the comparison table, by the label of the row: [free, pro], each the text of the cell.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function pricingRows(DOMXPath $page): array
{
    $rows = [];

    foreach ($page->query('//table//tr[th[@scope="row"]]') ?: [] as $row) {
        $label = landingSqueeze((string) $page->evaluate('string(th)', $row));
        $cells = $page->query('td', $row);

        $rows[$label] = [landingSqueeze($cells?->item(0)?->textContent ?? ''), landingSqueeze($cells?->item(1)?->textContent ?? '')];
    }

    return $rows;
}

describe('on an instance that sells nothing', function (): void {
    beforeEach(fn () => billingOff());

    it('does not exist, in any language, and the prefixed address of the default language is a 404 as well, not a redirect to one', function (string $path): void {
        $this->get($path)->assertNotFound();
    })->with(['/pricing', '/en/pricing', '/fr/pricing']);

    it('is not announced: no link in the header, the footer or the sitemap', function (): void {
        config(['app.indexable' => true]);

        $this->get('/sitemap.xml')->assertOk()->assertDontSee('pricing');
        expect(templateAttributes(templateDom($this->get('/templates')->assertOk()), '//a[contains(@href, "pricing")]', 'href'))->toBe([]);
    });

    it('does not count an amount of zero as something to sell', function (): void {
        billingOn();
        config(['billing.amounts.month' => 0]);

        expect(PricingPlans::selling())->toBeFalse();
        $this->get('/pricing')->assertNotFound();
    });
});

describe('on an instance that sells a plan', function (): void {
    beforeEach(fn () => billingOn());

    it('is served in the language of its address, whatever the cookie and the browser say', function (string $path, string $language, string $title, string $h1): void {
        $response = $this->withUnencryptedCookie('locale', $language === 'fr' ? 'en' : 'fr')->get($path, ['Accept-Language' => $language === 'fr' ? 'en' : 'fr'])->assertOk();
        $page = templateDom($response);

        expect(templateAttributes($page, '//html', 'lang'))->toBe([$language])
            ->and($response->headers->get('Content-Language'))->toBe($language)
            ->and(templateTexts($page, '//head/title'))->toBe([$title.' · '.config('app.name')])
            ->and(templateTexts($page, '//h1'))->toBe([$h1])
            ->and(collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->all())->not->toContain('locale');
    })->with([
        'French' => ['/pricing', 'fr', 'Tarifs : offres Gratuit et Pro', 'Gratuit pour démarrer. Pro pour grandir.'],
        'English' => ['/en/pricing', 'en', 'Pricing: Free and Pro plans', 'Free to start. Pro to grow.'],
    ]);

    it('redirects the prefixed address of the language of the instance to the one without prefix, for good', function (): void {
        $this->get('/fr/pricing?b=2&a=1')->assertStatus(301)->assertRedirect('/pricing?a=1&b=2');
    });

    it('does not exist in a language the application does not speak', function (): void {
        $this->get('/xx/pricing')->assertNotFound();
    });

    it('names its address and its other languages to search engines, and links to them', function (): void {
        config(['app.indexable' => true]);

        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/en/pricing'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'hreflang'))->toBe(['fr', 'en', 'x-default'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'href'))->toBe(['http://localhost/pricing', 'http://localhost/en/pricing', 'http://localhost/pricing'])
            ->and(templateAttributes($page, '//head/meta[@name="robots"]', 'content'))->toBe(['index, follow'])
            ->and(templateAttributes($page, '//a[@hreflang]', 'href'))->toBe(['/pricing', '/en/pricing'])
            ->and(templateAttributes($page, '//a[@hreflang and @aria-current]', 'hreflang'))->toBe(['en']);
    });

    it('does not ask to be indexed where the instance does not', function (): void {
        config(['app.indexable' => false]);

        expect(templateAttributes(templateDom($this->get('/pricing')->assertOk()), '//head/meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow']);
    });

    it('has one h1, a heading for each part, and the plans under theirs', function (): void {
        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['Free to start. Pro to grow.'])
            ->and(templateTexts($page, '//h2'))->toBe(['Plans', 'Compare the plans', 'Billing questions'])
            ->and(templateTexts($page, '//article//h3'))->toBe(['Free', 'Pro']);
    });

    it('adds itself to the header and the footer', function (): void {
        $english = templateDom($this->get('/en/pricing')->assertOk());
        $french = templateDom($this->get('/pricing')->assertOk());

        expect(templateAttributes($english, '//header//a[normalize-space()="Pricing"]', 'href'))->toBe(['/en/pricing'])
            ->and(templateAttributes($english, '//footer//a[normalize-space()="Pricing"]', 'href'))->toBe(['/en/pricing'])
            ->and(templateAttributes($french, '//header//a[normalize-space()="Tarifs"]', 'href'))->toBe(['/pricing'])
            ->and(templateAttributes($french, '//footer//a[normalize-space()="Tarifs"]', 'href'))->toBe(['/pricing']);
    });

    it('never calls a limit unlimited', function (string $path): void {
        expect(landingText($this->get($path)->assertOk()))->not->toMatch('/unlimited|illimité/i');
    })->with(['/pricing', '/en/pricing']);
});

describe('the amounts', function (): void {
    it('come from the configuration, as each language writes them', function (): void {
        billingOn();

        $english = templateDom($this->get('/en/pricing')->assertOk());
        $french = templateDom($this->get('/pricing')->assertOk());

        expect(templateTexts($english, '//*[contains(@class, "landing-price-amount")]'))->toBe(['€0', '€6', '€60'])
            ->and(templateTexts($french, '//*[contains(@class, "landing-price-amount")]'))->toBe(['0 €', '6 €', '60 €'])
            ->and(templateTexts($english, '//*[contains(@class, "landing-price-note") and contains(@class, "landing-only-year")]'))->toBe(['That is €5 a month'])
            ->and(templateTexts($french, '//*[contains(@class, "landing-price-note") and contains(@class, "landing-only-year")]'))->toBe(['Soit 5 € par mois']);
    });

    it('are not typed in the page: other amounts, other currency, other page', function (): void {
        billingOn();
        config(['billing.amounts.month' => 1250, 'billing.amounts.year' => 9990, 'billing.currency' => 'gbp']);

        $english = templateDom($this->get('/en/pricing')->assertOk());
        $french = templateDom($this->get('/pricing')->assertOk());

        expect(templateTexts($english, '//*[contains(@class, "landing-price-amount")]'))->toBe(['£0', '£12.50', '£99.90'])
            ->and(templateTexts($french, '//*[contains(@class, "landing-price-amount")]'))->toBe(['0 £GB', '12,50 £GB', '99,90 £GB'])
            ->and(templateTexts($english, '//*[contains(@class, "landing-toggle-saving")]'))->toBe(['Save £50.10 a year'])
            ->and(templateTexts($english, '//*[contains(@class, "landing-price-note") and contains(@class, "landing-only-year")]'))->toBe(['That is £8.33 a month'])
            ->and(templateAttributes($english, '//head/meta[@name="description"]', 'content'))->toBe(['Compare the Free and Pro plans: inventories, items, storage, members and share links. Pro from £12.50 a month, cancel anytime.']);
    });

    it('show the monthly price alone, and no switch, where there is no yearly Price', function (): void {
        billingOn(['services.stripe.prices.yearly' => null]);

        $response = $this->get('/en/pricing')->assertOk();
        $page = templateDom($response);

        expect(templateTexts($page, '//*[contains(@class, "landing-price-amount")]'))->toBe(['€0', '€6'])
            ->and($page->query('//input[@name="pricing-period"]')->length)->toBe(0)
            ->and($page->query('//*[@x-data="pricingPeriod"]')->length)->toBe(0)
            ->and($page->query('//*[contains(@class, "landing-only-year")]')->length)->toBe(0)
            ->and(landingText($response))->not->toContain('Yearly')->not->toContain('a year')
            ->and(templateAttributes($page, '//a[normalize-space()="Choose Pro"]', 'href'))->toBe(['/app/billing?interval=month']);
    });

    it('give no saving where the year costs as much as twelve months or more', function (): void {
        billingOn();
        config(['billing.amounts.month' => 500, 'billing.amounts.year' => 6000]);

        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect($page->query('//input[@name="pricing-period"]')->length)->toBe(2)
            ->and($page->query('//*[contains(@class, "landing-toggle-saving")]')->length)->toBe(0);
    });
});

describe('the switch between the monthly and the yearly price', function (): void {
    beforeEach(fn () => billingOn());

    it('is two native radios in a fieldset, hidden until the script starts, with monthly checked', function (): void {
        $page = templateDom($this->get('/en/pricing')->assertOk());
        $fieldset = $page->query('//fieldset[contains(@class, "landing-toggle")]')->item(0);

        expect($fieldset)->toBeInstanceOf(DOMElement::class)
            ->and($fieldset->hasAttribute('hidden'))->toBeTrue()
            ->and($fieldset->getAttribute(':hidden'))->toBe('!ready')
            ->and(templateTexts($page, '//fieldset//legend'))->toBe(['Billing period'])
            ->and(templateAttributes($page, '//input[@name="pricing-period"]', 'type'))->toBe(['radio', 'radio'])
            ->and(templateAttributes($page, '//input[@name="pricing-period"]', 'value'))->toBe(['month', 'year'])
            ->and(templateAttributes($page, '//input[@name="pricing-period"][@checked]', 'value'))->toBe(['month'])
            ->and(templateTexts($page, '//fieldset//label'))->toBe(['Monthly', 'Yearly Save €12 a year']);
    });

    it('puts both prices in the page, with the monthly one on show and the yearly one as a line when there is no script', function (): void {
        $page = templateDom($this->get('/en/pricing')->assertOk());
        $plans = $page->query('//*[@x-data="pricingPeriod"]')->item(0);

        expect($plans)->toBeInstanceOf(DOMElement::class)
            ->and($plans->getAttribute('data-period'))->toBe('month')
            ->and($plans->hasAttribute('data-ready'))->toBeFalse()
            ->and($plans->getAttribute(':data-period'))->toBe('period')
            ->and(templateTexts($page, '//*[contains(@class, "landing-only-month")]'))->toBe(['€6 / month'])
            ->and(templateTexts($page, '//p[contains(@class, "landing-price-line") and contains(@class, "landing-only-year")]'))->toBe(['€60 / year'])
            ->and(templateTexts($page, '//*[contains(@class, "landing-price-alt")]'))->toBe(['or €60 a year'])
            ->and(templateAttributes($page, '//a[normalize-space()="Choose Pro"]', 'href'))->toBe(['/app/billing?interval=month'])
            ->and(templateAttributes($page, '//a[normalize-space()="Choose Pro"]', ':href'))->toBe(['ctaHref'])
            ->and($plans->getAttribute('data-href-month'))->toBe('/app/billing?interval=month')
            ->and($plans->getAttribute('data-href-year'))->toBe('/app/billing?interval=year');
    });

    it('announces the price that shows to a screen reader', function (): void {
        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateAttributes($page, '//*[contains(@class, "landing-price")][@aria-live]', 'aria-live'))->toBe(['polite']);
    });
});

describe('the limits', function (): void {
    beforeEach(fn () => billingOn());

    it('are the ones of the plans, in a table a screen reader can read', function (): void {
        $response = $this->get('/en/pricing')->assertOk();
        $page = templateDom($response);
        $rows = pricingRows($page);

        expect($rows['Inventories'])->toBe(['3', '100'])
            ->and($rows['Items per inventory'])->toBe(['500', '10,000'])
            ->and($rows['File storage'])->toBe(['200 MB', '10 GB'])
            ->and($rows['Members (pending invitations included)'])->toBe(['3', '50'])
            ->and($rows['Share links per inventory'])->toBe(['2', '20']);

        expect(templateTexts($page, '//table/caption'))->toBe(['Compare the plans'])
            ->and(templateTexts($page, '//table/thead/tr/th[@scope="col"]'))->toBe(['Feature', 'Free', 'Pro'])
            ->and(templateTexts($page, '//table/thead/tr/th[1]/span[contains(@class, "visually-hidden")]'))->toBe(['Feature'])
            ->and(templateAttributes($page, '//div[contains(@class, "table-responsive")]', 'tabindex'))->toBe(['0'])
            ->and($page->query('//table//svg[@aria-hidden="true"]')->length)->toBeGreaterThan(5);
    });

    it('are read from the configuration, whatever the numbers: nothing is written in the page', function (): void {
        config([
            'billing.plans.free.limits' => ['inventories' => 7, 'items' => 1234, 'storage_bytes' => 128974848, 'members' => 1, 'share_links' => 0],
            'billing.plans.pro.limits' => ['inventories' => 1001, 'items' => 98765, 'storage_bytes' => 3865470566, 'members' => 12, 'share_links' => 321],
        ]);

        $english = pricingRows(templateDom($this->get('/en/pricing')->assertOk()));
        $french = pricingRows(templateDom($this->get('/pricing')->assertOk()));

        expect($english['Inventories'])->toBe(['7', '1,001'])
            ->and($english['Items per inventory'])->toBe(['1,234', '98,765'])
            ->and($english['File storage'])->toBe(['123 MB', '3.6 GB'])
            ->and($english['Members (pending invitations included)'])->toBe(['1', '12'])
            ->and($english['Share links per inventory'])->toBe(['0', '321'])
            ->and($french['Inventaires'])->toBe(['7', '1 001'])
            ->and($french['Articles par inventaire'])->toBe(['1 234', '98 765'])
            ->and($french['Stockage des fichiers'])->toBe(['123 Mo', '3,6 Go']);
    });

    it('are in the sentence of each plan, with the plurals of the language', function (): void {
        config(['billing.plans.free.limits.inventories' => 1, 'billing.plans.free.limits.members' => 1, 'billing.plans.free.limits.share_links' => 1, 'billing.plans.free.limits.items' => 1]);

        $english = landingText($this->get('/en/pricing')->assertOk());
        $french = landingText($this->get('/pricing')->assertOk());

        expect($english)->toContain('Up to 1 inventory of 1 item each, 200 MB of files, 1 member, and 1 share link per inventory.')
            ->toContain('Up to 100 inventories of 10,000 items each, 10 GB of files, 50 members, and 20 share links per inventory.')
            ->and($french)->toContain('Jusqu\'à 1 inventaire de 1 article chacun, 200 Mo de fichiers, 1 membre, et 1 lien de partage par inventaire.')
            ->toContain('Jusqu\'à 100 inventaires de 10 000 articles chacun, 10 Go de fichiers, 50 membres, et 20 liens de partage par inventaire.');
    });
});

describe('what the instance can do', function (): void {
    beforeEach(fn () => billingOn());

    it('lists the photo entry only where the instance offers it, with the plan that includes it', function (): void {
        $without = pricingRows(templateDom($this->get('/en/pricing')->assertOk()));

        expect($without)->not->toHaveKey('Add items from photos (AI)');
        expect(landingText($this->get('/en/pricing')->assertOk()))->not->toContain('photos');

        config(['services.anthropic.key' => 'sk-ant-test-secret']);
        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);

        $response = $this->get('/en/pricing')->assertOk();
        $with = pricingRows(templateDom($response));

        // Free has none (its configuration says so), Pro has it: told by a hidden word, not by a color.
        expect($with['Add items from photos (AI)'])->toBe(['Not included', 'Included'])
            ->and(landingText($response))->toContain('Add items from photos with AI.');

        config(['billing.plans.free.features.photo_entry' => true, 'billing.plans.pro.features.photo_entry' => false]);
        expect(pricingRows(templateDom($this->get('/en/pricing')->assertOk()))['Add items from photos (AI)'])->toBe(['Included', 'Not included']);

        config(['billing.plans.free.features.photo_entry' => false]);
        expect(pricingRows(templateDom($this->get('/en/pricing')->assertOk())))->not->toHaveKey('Add items from photos (AI)');
    });

    it('lists the signed export only where signatures can be asked for', function (): void {
        expect(pricingRows(templateDom($this->get('/en/pricing')->assertOk())))->not->toHaveKey('Signed official export');

        config(['services.documenso.url' => 'https://sign.example', 'services.documenso.api_key' => 'api_test']);

        expect(pricingRows(templateDom($this->get('/en/pricing')->assertOk()))['Signed official export'])->toBe(['Included', 'Included']);
    });

    it('answers about tax only where Stripe works it out', function (): void {
        $without = landingText($this->get('/en/pricing')->assertOk());

        expect($without)->not->toContain('What about VAT?');

        config(['billing.automatic_tax' => true]);

        expect(landingText($this->get('/en/pricing')->assertOk()))->toContain('What about VAT?');
        expect(Faq::billing(PricingPlans::current()))->toHaveCount(6);
    });
});

describe('the buttons', function (): void {
    beforeEach(fn () => billingOn());

    it('lead a visitor to the sign-in page for Free and to the billing page for Pro (which comes back after the sign-in)', function (): void {
        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateAttributes($page, '//article[@id or @aria-labelledby="plan-free"]//a[contains(@class, "btn")]', 'href'))->toBe(['/login'])
            ->and(templateAttributes($page, '//article[@aria-labelledby="plan-pro"]//a[contains(@class, "btn")]', 'href'))->toBe(['/app/billing?interval=month']);

        $this->get('/app/billing?interval=year')->assertRedirect(route('login'));
        expect(session('url.intended'))->toEndWith('/app/billing?interval=year');
    });

    it('lead a signed-in person to their space', function (): void {
        $page = templateDom($this->actingAs(User::factory()->create())->get('/en/pricing')->assertOk());

        expect(templateAttributes($page, '//article[@aria-labelledby="plan-free"]//a[contains(@class, "btn")]', 'href'))->toBe(['/app'])
            ->and(templateTexts($page, '//article[@aria-labelledby="plan-free"]//a[contains(@class, "btn")]'))->toBe(['Open my space']);
    });

    it('say who bills and how, once', function (): void {
        expect(landingText($this->get('/en/pricing')->assertOk()))->toContain('Billed by Stripe. Cancel anytime.');
    });
});

describe('the questions about billing', function (): void {
    beforeEach(fn () => billingOn());

    it('are the ones the structured data tells search engines about, word for word', function (string $path): void {
        $response = $this->get($path)->assertOk();
        $page = templateDom($response);
        $entities = landingJsonLdOf($response, 'FAQPage')['mainEntity'];

        expect(templateTexts($page, '//section[@id="billing-faq"]//button[contains(@class, "accordion-button")]'))
            ->toBe(array_map(fn (array $entity): string => landingSqueeze($entity['name']), $entities))
            ->and(templateTexts($page, '//section[@id="billing-faq"]//div[contains(@class, "accordion-body")]'))
            ->toBe(array_map(fn (array $entity): string => landingSqueeze($entity['acceptedAnswer']['text']), $entities));
    })->with(['/pricing', '/en/pricing']);

    it('do not promise a read-only workspace: going back to Free only refuses what is new', function (): void {
        $text = landingText($this->get('/en/pricing')->assertOk());

        expect($text)->toContain('Nothing is deleted.')->toContain('you cannot create new inventories, items, members or share links');
    });
});

describe('the structured data', function (): void {
    beforeEach(fn () => billingOn());

    it('is valid JSON of the types the page is about, with the plans as offers', function (): void {
        config(['billing.amounts.month' => 650, 'billing.amounts.year' => 6000]);

        $response = $this->get('/en/pricing')->assertOk();
        $types = array_column(landingJsonLd($response), '@type');

        expect($types)->toBe(['WebPage', 'BreadcrumbList', 'SoftwareApplication', 'FAQPage']);

        $breadcrumb = landingJsonLdOf($response, 'BreadcrumbList')['itemListElement'];
        $offers = landingJsonLdOf($response, 'SoftwareApplication')['offers'];

        expect(array_column($breadcrumb, 'item'))->toBe(['http://localhost/', 'http://localhost/en/pricing'])
            ->and(array_column($breadcrumb, 'name'))->toBe(['Home', 'Pricing'])
            ->and(landingJsonLdOf($response, 'WebPage'))->toMatchArray(['url' => 'http://localhost/en/pricing', 'inLanguage' => 'en'])
            ->and($offers)->toHaveCount(3)
            ->and($offers[0])->toMatchArray(['name' => 'Free', 'price' => '0'])
            ->and($offers[1]['priceSpecification'])->toMatchArray(['price' => '6.50', 'billingDuration' => 'P1M'])
            ->and($offers[2]['priceSpecification'])->toMatchArray(['price' => '60.00', 'billingDuration' => 'P1Y']);
    });

    it('says the same things in French, with the French address', function (): void {
        $response = $this->get('/pricing')->assertOk();

        expect(landingJsonLdOf($response, 'WebPage'))->toMatchArray(['url' => 'http://localhost/pricing', 'inLanguage' => 'fr'])
            ->and(array_column(landingJsonLdOf($response, 'BreadcrumbList')['itemListElement'], 'name'))->toBe(['Accueil', 'Tarifs']);
    });

    it('has no yearly offer where the instance sells no year', function (): void {
        billingOn(['services.stripe.prices.yearly' => null]);

        expect(landingJsonLdOf($this->get('/en/pricing')->assertOk(), 'SoftwareApplication')['offers'])->toHaveCount(2);
    });
});

describe('the sitemap', function (): void {
    /**
     * The addresses the sitemap lists, with the languages each is announced in.
     *
     * @return array<string, array<string, string>>
     */
    function pricingSitemap(mixed $test): array
    {
        $document = new DOMDocument;
        $document->loadXML($test->get('/sitemap.xml')->assertOk()->getContent());
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
        $entries = [];

        foreach ($xpath->query('/s:urlset/s:url') ?: [] as $url) {
            $alternates = [];

            foreach ($xpath->query('x:link[@rel="alternate"]', $url) ?: [] as $link) {
                /** @var DOMElement $link */
                $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
            }

            $entries[(string) $xpath->evaluate('string(s:loc)', $url)] = $alternates;
        }

        return $entries;
    }

    beforeEach(fn () => config(['app.indexable' => true]));

    it('lists the pricing page in every language, with its alternates, where a plan is sold', function (): void {
        billingOn();

        $entries = pricingSitemap($this);

        expect($entries)->toHaveKeys(['http://localhost/pricing', 'http://localhost/en/pricing'])
            ->and($entries['http://localhost/en/pricing'])->toBe(['fr' => 'http://localhost/pricing', 'en' => 'http://localhost/en/pricing', 'x-default' => 'http://localhost/pricing']);
    });

    it('does not list it where nothing is sold', function (): void {
        billingOff();

        expect(array_keys(pricingSitemap($this)))->not->toContain('http://localhost/pricing')->not->toContain('http://localhost/en/pricing');
    });

    it('is not there at all where the instance is not indexable', function (): void {
        billingOn();
        config(['app.indexable' => false]);

        $this->get('/sitemap.xml')->assertNotFound();
    });
});
