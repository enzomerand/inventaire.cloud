<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/landing.php';

uses(RefreshDatabase::class);

/*
 * The terms of sale: /terms (French, the version that prevails) and /en/terms (a translation for information). They exist where the plans
 * are shown (an instance that sells, or previews its plans), once per language like the pricing page, and while the identity of the seller
 * is incomplete they are a working version: a banner and no indexing.
 */

/** The French banner of a working version. */
const TERMS_DRAFT_FR = 'Version de travail : informations à compléter';

/**
 * The paragraphs of the annex (the statutory articles) of the terms on a page: the French ones follow the heading of the annex, the
 * English page keeps them in a French block.
 *
 * @return list<string>
 */
function termsAnnex(DOMXPath $page): array
{
    $french = templateTexts($page, '//article[contains(@class, "legal")]//h2[starts-with(normalize-space(), "Annexe")]/following-sibling::p');

    return $french !== [] ? $french : templateTexts($page, '//article[contains(@class, "legal")]//div[@lang="fr"]/p');
}

describe('on an instance that neither sells nor previews its plans', function (): void {
    beforeEach(fn () => billingOff());

    it('does not exist, in any language, and the prefixed address of the default language is a 404 as well, not a redirect to one', function (string $path): void {
        $this->get($path)->assertNotFound();
    })->with(['/terms', '/en/terms', '/fr/terms']);

    it('is linked from nowhere', function (): void {
        foreach (['/', '/templates', '/en/templates'] as $path) {
            $html = landingMarkup($this->get($path)->assertOk());

            expect($html)->not->toContain('/terms')->not->toContain('Conditions générales de vente')->not->toContain('Terms of sale');
        }
    });

    it('does not count a preview of plans without an amount as something to show', function (): void {
        billingPreview();
        config(['billing.amounts.month' => 0]);

        $this->get('/terms')->assertNotFound();
    });
});

describe('on an instance that previews its plans', function (): void {
    beforeEach(function (): void {
        billingPreview();
        billingSeller();
    });

    it('is served in the language of its address, whatever the cookie and the browser say', function (string $path, string $language, string $title, string $h1): void {
        $response = $this->withUnencryptedCookie('locale', $language === 'fr' ? 'en' : 'fr')->get($path, ['Accept-Language' => $language === 'fr' ? 'en' : 'fr'])->assertOk();
        $page = templateDom($response);

        expect(templateAttributes($page, '//html', 'lang'))->toBe([$language])
            ->and($response->headers->get('Content-Language'))->toBe($language)
            ->and(templateTexts($page, '//head/title'))->toBe([$title.' · '.config('app.name')])
            ->and(templateTexts($page, '//h1'))->toBe([$h1])
            ->and(collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->all())->not->toContain('locale');
    })->with([
        'French' => ['/terms', 'fr', 'Conditions générales de vente', 'Conditions générales de vente'],
        'English' => ['/en/terms', 'en', 'Terms of sale', 'Terms of sale'],
    ]);

    it('redirects the prefixed address of the language of the instance to the one without prefix, for good', function (): void {
        $this->get('/fr/terms?b=2&a=1')->assertStatus(301)->assertRedirect('/terms?a=1&b=2');
    });

    it('does not exist in a language the application does not speak', function (): void {
        $this->get('/xx/terms')->assertNotFound();
    });

    it('shows the date of its version', function (): void {
        expect(landingText($this->get('/terms')->assertOk()))->toContain('Version du 31 octobre 2026')
            ->and(landingText($this->get('/en/terms')->assertOk()))->toContain('Version of October 31, 2026');
    });

    it('names its address and its other languages to search engines, and links to them', function (): void {
        config(['app.indexable' => true]);

        $page = templateDom($this->get('/en/terms')->assertOk());

        expect(templateAttributes($page, '//head/link[@rel="canonical"]', 'href'))->toBe(['http://localhost/en/terms'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'hreflang'))->toBe(['fr', 'en', 'x-default'])
            ->and(templateAttributes($page, '//head/link[@rel="alternate"]', 'href'))->toBe(['http://localhost/terms', 'http://localhost/en/terms', 'http://localhost/terms'])
            ->and(templateAttributes($page, '//head/meta[@name="robots"]', 'content'))->toBe(['index, follow'])
            ->and(templateAttributes($page, '//a[@hreflang and @aria-current]', 'hreflang'))->toBe(['en']);
    });

    it('does not ask to be indexed where the instance does not', function (): void {
        config(['app.indexable' => false]);

        expect(templateAttributes(templateDom($this->get('/terms')->assertOk()), '//head/meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow']);
    });

    it('has the structured data of a page and its breadcrumb', function (): void {
        $response = $this->get('/terms')->assertOk();
        $breadcrumb = landingJsonLdOf($response, 'BreadcrumbList')['itemListElement'];

        expect(array_column(landingJsonLd($response), '@type'))->toBe(['WebPage', 'BreadcrumbList'])
            ->and(landingJsonLdOf($response, 'WebPage'))->toMatchArray(['url' => 'http://localhost/terms', 'inLanguage' => 'fr'])
            ->and(array_column($breadcrumb, 'name'))->toBe(['Accueil', 'Conditions générales de vente']);
    });
});

describe('the working version', function (): void {
    beforeEach(function (): void {
        billingPreview();
        config(['app.indexable' => true]);
    });

    it('is announced by a banner and asks not to be indexed while a required detail is missing', function (string $key): void {
        billingSeller($key);

        $french = $this->get('/terms')->assertOk();
        $english = $this->get('/en/terms')->assertOk();

        expect(landingText($french))->toContain(TERMS_DRAFT_FR)
            ->and(landingText($english))->toContain('Working version: information to be completed')
            ->and(templateAttributes(templateDom($french), '//head/meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow'])
            ->and(templateAttributes(templateDom($english), '//head/meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow']);
    })->with(['vat_number', 'mediator_url', 'name']);

    it('has no banner and is indexed once the seller is complete', function (): void {
        billingSeller();

        $response = $this->get('/terms')->assertOk();

        expect(landingText($response))->not->toContain(TERMS_DRAFT_FR)
            ->and(templateAttributes(templateDom($response), '//head/meta[@name="robots"]', 'content'))->toBe(['index, follow']);
    });

    it('prints the mark of each missing detail where the detail goes', function (): void {
        billingSeller('vat_number', 'mediator_name');

        $text = landingText($this->get('/terms')->assertOk());

        expect($text)->toContain('N° de TVA intracommunautaire : [à compléter]')
            ->toContain('médiateur de la consommation dont relève le Vendeur : [à compléter],')
            ->toContain('Palan Test, SAS');
    });
});

describe('the French terms', function (): void {
    beforeEach(function (): void {
        billingPreview();
        billingSeller();
    });

    it('name the seller, with the capital when there is one', function (): void {
        expect(landingText($this->get('/terms')->assertOk()))
            ->toContain('Palan Test, SAS, au capital de 1 000 euros')
            ->toContain('Siège : 1 rue de Test, 44000 Nantes')
            ->toContain('Immatriculation : RCS Nantes 123 456 789')
            ->toContain('N° de TVA intracommunautaire : FR12345678901')
            ->toContain('Courriel : ventes@example.test — Téléphone : +33 2 00 00 00 00')
            ->toContain('Médiateur Test, 2 rue du Médiateur, 75000 Paris, https://mediateur.example.test');

        config(['legal.seller.capital' => '']);

        expect(landingText($this->get('/terms')->assertOk()))->toContain('Palan Test, SAS')->not->toContain('au capital');
    });

    it('have the articles of the specification, then the annex', function (): void {
        $headings = templateTexts(templateDom($this->get('/terms')->assertOk()), '//article[contains(@class, "legal")]//h2');

        expect($headings)->toHaveCount(18)
            ->and($headings[0])->toBe('Préambule')
            ->and($headings[1])->toBe('Article 1 — Vendeur')
            ->and($headings[7])->toBe('Article 7 — Droit de rétractation')
            ->and($headings[16])->toBe('Article 16 — Droit applicable et juridiction')
            ->and($headings[17])->toStartWith('Annexe — Articles du code de la consommation reproduits en application de l\'article L. 215-4');
    });

    it('carry the model withdrawal form', function (): void {
        $response = $this->get('/terms')->assertOk();
        $text = landingText($response);

        expect(templateTexts(templateDom($response), '//article[contains(@class, "legal")]//h3'))->toBe(['Formulaire type de rétractation'])
            ->and($text)->toContain('(Veuillez compléter et renvoyer le présent formulaire uniquement si vous souhaitez vous rétracter du contrat.)')
            ->toContain('À l\'attention de Palan Test, 1 rue de Test, 44000 Nantes, ventes@example.test :')
            ->toContain('Signature du (des) consommateur(s) (uniquement en cas de notification du présent formulaire sur papier) :')
            ->toContain('(*) Rayez la mention inutile.')
            ->and($text)->toContain('bouton « Renoncer au contrat ici »')->toContain('bouton « Résilier votre contrat »');
    });

    it('state the prices of the configuration, VAT included, and the limits of the plans', function (): void {
        $text = landingText($this->get('/terms')->assertOk());

        expect($text)->toContain('L\'abonnement Pro coûte 6 € TTC par mois, ou 60 € TTC par an, payables d\'avance.');

        config(['billing.amounts.month' => 650, 'billing.amounts.year' => 7000, 'billing.plans.pro.limits.inventories' => 250]);

        $page = templateDom($this->get('/terms')->assertOk());

        expect(landingText($this->get('/terms')->assertOk()))->toContain('6,50 € TTC par mois, ou 70 € TTC par an')
            ->and(templateTexts($page, '//article[contains(@class, "legal")]//table//tr[td="Inventaires"]/td'))->toBe(['Inventaires', '3 inventaires', '250 inventaires']);
    });

    it('state the monthly price alone where there is no yearly price', function (): void {
        config(['billing.amounts.year' => 0]);

        expect(landingText($this->get('/terms')->assertOk()))->toContain('L\'abonnement Pro coûte 6 € TTC par mois, payables d\'avance.')->not->toContain('par an, payables');
    });

    it('have no class in their markup: the page styles them', function (): void {
        $page = templateDom($this->get('/terms')->assertOk());

        expect($page->query('//article[contains(@class, "legal")]//*[@class]')->length)->toBe(0);
    });

    it('reproduce the annex: eleven paragraphs of statutory text', function (): void {
        $annex = termsAnnex(templateDom($this->get('/terms')->assertOk()));

        expect($annex)->toHaveCount(11)
            ->and($annex[0])->toStartWith('Article L. 215-1. Pour les contrats de prestations de services conclus pour une durée déterminée')
            ->and(implode(' ', $annex))->toContain('Article L. 215-1-1.')->toContain('Article L. 215-2.')->toContain('Article L. 215-3.')->toContain('Article L. 241-3.');
    });
});

describe('the English terms', function (): void {
    beforeEach(function (): void {
        billingPreview();
        billingSeller();
    });

    it('open with the notice that the French version prevails, and link to it', function (): void {
        $page = templateDom($this->get('/en/terms')->assertOk());
        $first = templateTexts($page, '//article[contains(@class, "legal")]/p[1]')[0];

        expect($first)->toStartWith('This translation is provided for information; the French version prevails.')
            ->and(templateAttributes($page, '//article[contains(@class, "legal")]/p[1]/a', 'href'))->toBe(['/terms']);
    });

    it('translate every article, the seller, the prices and the limits', function (): void {
        $response = $this->get('/en/terms')->assertOk();
        $headings = templateTexts(templateDom($response), '//article[contains(@class, "legal")]//h2');

        expect($headings)->toHaveCount(18)
            ->and($headings[1])->toBe('Article 1 — Seller')
            ->and($headings[7])->toBe('Article 7 — Right of withdrawal')
            ->and(landingText($response))
            ->toContain('Palan Test, SAS, with a share capital of 1 000 euros')
            ->toContain('Intra-Community VAT number: FR12345678901')
            ->toContain('The Pro subscription costs €6 incl. VAT per month, or €60 incl. VAT per year, payable in advance.')
            ->and(templateTexts(templateDom($response), '//article[contains(@class, "legal")]//table//tr[td="Inventories"]/td'))->toBe(['Inventories', '3 inventories', '100 inventories']);
    });

    it('carry the model withdrawal form', function (): void {
        $response = $this->get('/en/terms')->assertOk();
        $text = landingText($response);

        expect(templateTexts(templateDom($response), '//article[contains(@class, "legal")]//h3'))->toBe(['Model withdrawal form'])
            ->and($text)->toContain('(Please complete and return this form only if you wish to withdraw from the contract.)')
            ->toContain('For the attention of Palan Test, 1 rue de Test, 44000 Nantes, ventes@example.test:')
            ->toContain('(*) Delete as appropriate.');
    });

    it('keep the statutory articles of the annex in French, word for word as the French terms have them', function (): void {
        $french = termsAnnex(templateDom($this->get('/terms')->assertOk()));
        $english = termsAnnex(templateDom($this->get('/en/terms')->assertOk()));

        expect($english)->toHaveCount(11)->toBe($french);
    });

    it('have no class in their markup either', function (): void {
        expect(templateDom($this->get('/en/terms')->assertOk())->query('//article[contains(@class, "legal")]//*[@class]')->length)->toBe(0);
    });
});

describe('the links to the terms', function (): void {
    it('are in the footer where the plans are shown, in the language of the page, and nowhere else', function (): void {
        billingOff();
        $none = templateDom($this->get('/en/templates')->assertOk());

        expect(templateAttributes($none, '//footer//a[contains(@href, "terms")]', 'href'))->toBe([]);

        billingPreview();
        $english = templateDom($this->get('/en/templates')->assertOk());
        $french = templateDom($this->get('/templates')->assertOk());

        expect(templateAttributes($english, '//footer//a[normalize-space()="Terms of sale"]', 'href'))->toBe(['/en/terms'])
            ->and(templateAttributes($french, '//footer//a[normalize-space()="Conditions générales de vente"]', 'href'))->toBe(['/terms'])
            ->and(templateAttributes($english, '//header//a[contains(@href, "terms")]', 'href'))->toBe([]);

        billingOn();
        $selling = templateDom($this->get('/en/templates')->assertOk());

        expect(templateAttributes($selling, '//footer//a[normalize-space()="Terms of sale"]', 'href'))->toBe(['/en/terms']);
    });

    it('are beside the plans and the comparison of the pricing page', function (): void {
        billingPreview();

        $page = templateDom($this->get('/en/pricing')->assertOk());

        expect(templateAttributes($page, '//main//a[normalize-space()="Terms of sale"]', 'href'))->toBe(['/en/terms', '/en/terms']);

        $home = templateDom($this->get('/', ['Accept-Language' => 'fr'])->assertOk());

        expect(templateAttributes($home, '//section[@id="pricing"]//a[normalize-space()="Conditions générales de vente"]', 'href'))->toBe(['/terms']);
    });
});

describe('the sitemap', function (): void {
    /**
     * The addresses the sitemap lists, with the languages each is announced in.
     *
     * @return array<string, array<string, string>>
     */
    function termsSitemap(mixed $test): array
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

    it('lists the terms in every language, with their alternates, where a plan is sold', function (): void {
        billingOn();

        $entries = termsSitemap($this);

        expect($entries)->toHaveKeys(['http://localhost/terms', 'http://localhost/en/terms'])
            ->and($entries['http://localhost/en/terms'])->toBe(['fr' => 'http://localhost/terms', 'en' => 'http://localhost/en/terms', 'x-default' => 'http://localhost/terms']);
    });

    it('does not list them for a preview, which sells nothing, nor where the seller is incomplete', function (): void {
        billingPreview();
        billingSeller();

        expect(array_keys(termsSitemap($this)))->not->toContain('http://localhost/terms')->not->toContain('http://localhost/pricing');

        billingOn();
        billingSeller('vat_number');

        expect(array_keys(termsSitemap($this)))->not->toContain('http://localhost/terms')->not->toContain('http://localhost/en/pricing');
    });
});

it('is read by a signed-in person as by a visitor', function (): void {
    billingPreview();

    $this->actingAs(User::factory()->create())->get('/terms')->assertOk();
});
