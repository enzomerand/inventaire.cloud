<?php

declare(strict_types=1);

use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCatalogue;
use App\Inventories\Templates\TemplatePresenter;
use App\Support\PublicUrls;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * The public pages of the templates: the gallery and one page for each template, once per language, drawn by the server.
 * The fixtures of tests/Fixtures/templates are the catalogue (the shipped ones are checked at the end).
 */

beforeEach(fn () => templateFixtures());

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

describe('the language of a public page', function (): void {
    it('comes from the address alone: no prefix is the language of the instance, whatever the visitor asks for', function (): void {
        $response = $this->withUnencryptedCookie('locale', 'en')->get('/templates', ['Accept-Language' => 'en'])->assertOk();

        expect(templateAttributes(templateDom($response), '//html', 'lang'))->toBe(['fr'])
            ->and(templateTexts(templateDom($response), '//h1'))->toBe(["Modèles d'inventaire"])
            ->and($response->headers->get('Content-Language'))->toBe('fr');
    });

    it('is the one of the prefix, whatever the cookie and the browser say', function (): void {
        $response = $this->withUnencryptedCookie('locale', 'fr')->get('/en/templates', ['Accept-Language' => 'fr'])->assertOk();

        expect(templateAttributes(templateDom($response), '//html', 'lang'))->toBe(['en'])
            ->and(templateTexts(templateDom($response), '//h1'))->toBe(['Inventory templates'])
            ->and($response->headers->get('Content-Language'))->toBe('en');
    });

    it('never writes the language cookie', function (string $path): void {
        $response = $this->get($path, ['Accept-Language' => 'en'])->assertOk();

        expect(collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->all())->not->toContain('locale');
    })->with(['/templates', '/en/templates', '/templates/sample-tools', '/en/templates/sample-tools']);

    it('redirects the prefixed address of the language of the instance to the one without prefix, for good', function (string $from, string $to): void {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    })->with([
        ['/fr/templates', '/templates'],
        ['/fr/templates/sample-tools', '/templates/sample-tools'],
        // The framework writes the query string with its parameters in alphabetical order.
        ['/fr/templates/sample-tools?utm=x&a=1', '/templates/sample-tools?a=1&utm=x'],
    ]);

    it('redirects to the address of the instance (APP_URL), never to the Host header of the request', function (string $from, string $to): void {
        config(['app.url' => 'https://inventaire.example/']);

        $response = $this->get($from, ['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example'])->assertStatus(301);

        expect($response->headers->get('Location'))->toBe($to);
    })->with([
        ['/fr/templates', 'https://inventaire.example/templates'],
        ['/fr/templates/sample-tools', 'https://inventaire.example/templates/sample-tools'],
        ['/fr/templates/sample-tools?b=2&a=1', 'https://inventaire.example/templates/sample-tools?a=1&b=2'],
    ]);

    it('is not found for a language the application does not support', function (string $path): void {
        $this->get($path)->assertNotFound();
    })->with(['/de/templates', '/de/templates/sample-tools', '/EN/templates', '/en-US/templates', '/xx/templates', '/app/templates']);

    it('changes with the language of the instance', function (): void {
        config(['app.default_locale' => 'en']);

        $this->get('/templates', ['Accept-Language' => 'fr'])->assertOk()->assertSee('Inventory templates');
    });

    it('names the button of the language switcher after the language it shows, as a person who sees it says it', function (): void {
        $page = templateDom($this->get('/en/templates/sample-tools')->assertOk());
        $button = '//button[@data-bs-toggle="dropdown"]';

        // No aria-label that replaces the visible words: the name is made of them, the sentence that says what the button does first.
        expect($page->query($button)->length)->toBe(1)
            ->and($page->query($button.'[@aria-label]')->length)->toBe(0)
            ->and(templateTexts($page, $button.'/span[contains(@class, "visually-hidden")]'))->toBe(['Change language'])
            ->and(templateTexts($page, $button.'/span[not(@class)]'))->toBe(['English'])
            ->and(templateTexts($page, $button))->toBe(['Change language English']);

        $french = templateDom($this->get('/templates/sample-tools')->assertOk());

        expect(templateTexts($french, $button))->toBe(['Changer de langue Français']);
    });

    it('links to the same page in every language from the switcher, which stores nothing', function (): void {
        $page = templateDom($this->get('/en/templates/sample-tools')->assertOk());

        expect(templateAttributes($page, '//a[@hreflang]', 'href'))->toBe(['/templates/sample-tools', '/en/templates/sample-tools'])
            ->and(templateAttributes($page, '//a[@hreflang and @aria-current]', 'hreflang'))->toBe(['en'])
            ->and($page->query('//*[@data-locale]')->length)->toBe(0);

        $gallery = templateDom($this->get('/templates')->assertOk());

        expect(templateAttributes($gallery, '//a[@hreflang]', 'href'))->toBe(['/templates', '/en/templates'])
            ->and(templateAttributes($gallery, '//a[@hreflang and @aria-current]', 'hreflang'))->toBe(['fr']);
    });

    it('leaves the switcher of the other pages as it was', function (): void {
        $page = templateDom($this->get('/')->assertOk());

        expect($page->query('//*[@data-locale]')->length)->toBe(2)->and($page->query('//a[@hreflang]')->length)->toBe(0);
    });
});

describe('the gallery', function (): void {
    it('lists the templates under the heading of their category, in the order of the categories', function (): void {
        $page = templateDom($this->get('/templates')->assertOk());

        expect(templateTexts($page, '//h2'))->toBe(['Maison et famille', 'Commerce et entreprise', 'Loisirs et atelier'])
            ->and(templateTexts($page, '//h3'))->toBe(['Inventaire du logement', 'Stock de boutique', 'Outils', 'Vinyles'])
            ->and(templateAttributes($page, '//h3/a[contains(@class, "stretched-link")]', 'href'))->toBe(['/templates/sample-home', '/templates/sample-shop', '/templates/sample-tools', '/templates/sample-records']);
    });

    it('shows the summary and the number of fields of each template', function (): void {
        $page = templateDom($this->get('/en/templates')->assertOk());

        expect(templateTexts($page, '//h2'))->toBe(['Home and family', 'Business and retail', 'Hobbies and workshop'])
            ->and(templateTexts($page, '//div[contains(@class, "app-template-card")]//p'))->toContain('Track your products, quantities and prices, with the barcode of each product.')
            ->and(templateTexts($page, '//div[contains(@class, "app-template-card")]//div[contains(@class, "small")]'))->toBe(['13 fields', '8 fields', '5 fields', '6 fields'])
            ->and(templateAttributes($page, '//h3/a[contains(@class, "stretched-link")]', 'href'))->toBe(['/en/templates/sample-home', '/en/templates/sample-shop', '/en/templates/sample-records', '/en/templates/sample-tools']);
    });

    it('draws the icon of each template and nothing the file says', function (): void {
        $page = templateDom($this->get('/templates')->assertOk());

        expect($page->query('//div[contains(@class, "app-template-card")]//svg')->length)->toBe(4);
    });

    it('makes the title of each card its link, and nothing else: a card is not one link around a heading and a paragraph', function (): void {
        $page = templateDom($this->get('/templates')->assertOk());

        expect($page->query('//div[contains(@class, "app-template-card")]')->length)->toBe(4)
            ->and(templateTexts($page, '//div[contains(@class, "app-template-card")]//h3/a'))->toBe(['Inventaire du logement', 'Stock de boutique', 'Outils', 'Vinyles'])
            ->and($page->query('//div[contains(@class, "app-template-card")]//a')->length)->toBe(4)
            ->and(templateAttributes($page, '//div[contains(@class, "app-template-card")]//a', 'class'))->each->toContain('stretched-link')
            // Nothing a link wraps but its own words.
            ->and($page->query('//a[.//h2 or .//h3 or .//p or .//div]')->length)->toBe(0);
    });

    it('says so when there is no template, and is still a page', function (): void {
        templateCatalogueFrom(storage_path('framework/testing/no-such-folder'));

        $this->get('/templates')->assertOk()->assertSee('Aucun modèle');
    });

    it('is open to a visitor who is not signed in and to one who is', function (): void {
        $this->get('/templates')->assertOk();
        $this->actingAs(inventoryPerson())->get('/templates')->assertOk();
    });
});

describe('the page of a template', function (): void {
    it('shows its name, its summary and its description', function (): void {
        $page = templateDom($this->get('/templates/sample-home')->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['Inventaire du logement'])
            ->and(templateTexts($page, '//title'))->toBe(['Inventaire du logement : modèle d\'inventaire gratuit · '.config('app.name')])
            ->and(templateTexts($page, '//p[contains(@class, "lead")]'))->toBe(['Listez vos biens pièce par pièce, avec leur valeur, leur facture et leur garantie.'])
            ->and(templateTexts($page, '//div[contains(@class, "col-lg-8")]//p[not(contains(@class, "lead"))]'))->toBe([
                'Ce modèle sert à garder une trace de tout ce que contient votre logement, par exemple pour votre assurance habitation.',
                'Ajoutez une photo et la facture de chaque bien : en cas de sinistre, tout est déjà prêt.',
            ]);
    });

    it('is in English at the prefixed address', function (): void {
        $page = templateDom($this->get('/en/templates/sample-home')->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['Home inventory'])
            ->and(templateTexts($page, '//title'))->toBe(['Home inventory: free inventory template · '.config('app.name')]);
    });

    it('lists the fields with their type, whether they are required, and the badges of their choices', function (): void {
        $page = templateDom($this->get('/en/templates/sample-home')->assertOk());
        $rows = '(//table)[1]/tbody/tr';

        expect(templateTexts($page, "{$rows}/th"))->toBe(['Name', 'Room', 'Categories', 'Purchase price', 'Purchase date', 'Warranty until', 'Condition', 'Insured', 'Weight', 'Tags', 'Notes', 'Photo', 'Invoice'])
            ->and(templateTexts($page, "{$rows}/td[1]"))->toBe(['Text', 'Choice', 'Multiple choice', 'Amount', 'Date', 'Date', 'Rating', 'Yes / no', 'Number', 'Tags', 'Long text', 'Photo', 'Attached file'])
            ->and(templateTexts($page, "{$rows}/td[2]"))->toBe(['Yes', 'No', 'No', 'No', 'No', 'No', 'No', 'No', 'No', 'No', 'No', 'No', 'No'])
            ->and(templateTexts($page, "{$rows}[2]/td[3]//span[contains(@class, 'badge')]"))->toBe(['Kitchen', 'Living room', 'Bedroom', 'Garage'])
            ->and(templateAttributes($page, "{$rows}[2]/td[3]//span[contains(@class, 'badge')]", 'class'))->toBe(['badge bg-blue-lt', 'badge bg-green-lt', 'badge bg-purple-lt', 'badge bg-secondary-lt'])
            ->and(templateTexts($page, "{$rows}[4]/td[3]"))->toBe(['EUR'])
            ->and(templateTexts($page, "{$rows}[9]/td[3]"))->toBe(['kg']);
    });

    it('shows the example rows the way the language writes them', function (): void {
        $page = templateDom($this->get('/en/templates/sample-home')->assertOk());
        $table = '(//table)[2]';

        // A column for each field that has an example: no photo, no invoice.
        expect(templateTexts($page, "{$table}/thead/tr/th"))->toBe(['Name', 'Room', 'Categories', 'Purchase price', 'Purchase date', 'Warranty until', 'Condition', 'Insured', 'Weight', 'Tags', 'Notes'])
            ->and(templateTexts($page, "{$table}/tbody/tr[1]/td"))->toBe(['Washing machine', 'Kitchen', 'Appliance', '€649.00', 'Sep 14, 2023', 'Sep 14, 2026', '★★★★☆', 'Yes', '68.5 kg', 'warranty kitchen', '—'])
            ->and(templateTexts($page, "{$table}/tbody/tr[3]/td"))->toBe(['Hammer drill', 'Garage', 'Electronics Appliance', '€89.90', '—', '—', '★★★☆☆', 'No', '—', '—', 'Lent to the neighbour in May.'])
            ->and(templateAttributes($page, "{$table}/tbody/tr[1]/td[7]/span", 'aria-label'))->toBe(['4 out of 5']);
    });

    it('writes the examples in French in French', function (): void {
        $page = templateDom($this->get('/templates/sample-home')->assertOk());

        expect(templateTexts($page, '(//table)[2]/tbody/tr[1]/td'))->toBe(['Lave-linge', 'Cuisine', 'Électroménager', '649,00 €', '14 sept. 2023', '14 sept. 2026', '★★★★☆', 'Oui', '68,5 kg', 'garantie cuisine', '—'])
            ->and(templateTexts($page, '(//table)[1]/tbody/tr/td[1]'))->toContain('Choix', 'Choix multiple', 'Montant');
    });

    it('gives each table a caption that only a screen reader shows, naming the table after the template', function (): void {
        $english = templateDom($this->get('/en/templates/sample-records')->assertOk());
        $french = templateDom($this->get('/templates/sample-records')->assertOk());

        expect($english->query('//table')->length)->toBe(2)
            ->and(templateTexts($english, '//table/caption'))->toBe(['Fields of the template Records', 'Example rows of the template Records'])
            ->and(templateAttributes($english, '//table/caption', 'class'))->toBe(['visually-hidden', 'visually-hidden'])
            // The first child of the table, as the caption must be.
            ->and($english->query('//table/*[1][self::caption]')->length)->toBe(2)
            ->and(templateTexts($french, '//table/caption'))->toBe(['Champs du modèle Vinyles', 'Exemples de lignes du modèle Vinyles']);
    });

    it('draws a rating as an image with its grade as a sentence, in the color of the stars', function (): void {
        $page = templateDom($this->get('/en/templates/sample-tools')->assertOk());
        $stars = '(//table)[2]//span[@role="img"]';

        expect(templateAttributes($page, $stars, 'aria-label'))->toBe(['4 out of 5', '2 out of 5'])
            ->and(templateTexts($page, $stars))->toBe(['★★★★☆', '★★☆☆☆'])
            ->and(templateAttributes($page, $stars, 'class'))->each->toContain('app-stars')->not->toContain('text-yellow');
    });

    it('draws the stars in a color that has at least 3:1 contrast with the card they are on', function (): void {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        expect(preg_match('/--app-stars-color:\s*(#[0-9a-f]{6});/i', $css, $match))->toBe(1)
            ->and(preg_match('/\.app-stars\s*\{\s*color:\s*var\(--app-stars-color\);/', $css))->toBe(1);

        // The relative luminance and the contrast ratio of WCAG 2.
        $luminance = function (string $hex): float {
            $channels = array_map(function (string $part): float {
                $value = hexdec($part) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $contrast = ($luminance('#ffffff') + 0.05) / ($luminance($match[1]) + 0.05);

        expect($contrast)->toBeGreaterThanOrEqual(3.0);
    });

    it('formats numbers, percentages, codes, colors and moments', function (): void {
        $shop = templateDom($this->get('/en/templates/sample-shop')->assertOk());
        $records = templateDom($this->get('/en/templates/sample-records')->assertOk());

        expect(templateTexts($shop, '(//table)[2]/tbody/tr[1]/td'))->toBe(['4006381333931', 'A5 notebook', '42', '€3.50', '20.0%', 'In stock', '—', '—'])
            ->and(templateTexts($shop, '(//table)[2]/tbody/tr[2]/td[7]'))->toBe(['https://example.com/pens'])
            ->and(templateAttributes($shop, '(//table)[2]/tbody/tr[1]/td[1]/span', 'class'))->toBe(['font-monospace text-break'])
            ->and(templateTexts($records, '(//table)[2]/tbody/tr[1]/td'))->toBe(['Kind of Blue', 'Miles Davis', '1959', '—', '#000000'])
            ->and(templateAttributes($records, '(//table)[2]/tbody/tr[1]/td[5]//span[contains(@class, "app-swatch")]', 'style'))->toBe(['background-color: #000000'])
            ->and(templateTexts($records, '(//table)[2]/tbody/tr[2]/td[4]'))->toBe(['May 18, 2024, 8:30 AM']);
    });

    it('writes a moment in the timezone of the instance', function (): void {
        config(['app.display_timezone' => 'Europe/Paris']);

        $page = templateDom($this->get('/en/templates/sample-records')->assertOk());

        expect(templateTexts($page, '(//table)[2]/tbody/tr[2]/td[4]'))->toBe(['May 18, 2024, 10:30 AM']);
    });

    it('shows a day or a moment it cannot format as the file writes it, and logs a warning', function (): void {
        $data = templateData('sample-records');
        $data['fields'][] = ['key' => 'released_on', 'type' => 'date', 'name' => ['fr' => 'Sorti le', 'en' => 'Released on']];
        // The validator would never let these in: the template is built by hand to reach the presenter.
        $data['examples']['en'][0]['bought_at'] = 'not a moment';
        $data['examples']['en'][0]['released_on'] = 'not a day';
        $template = Template::fromArray('records', $data);
        Log::spy();

        $examples = app(TemplatePresenter::class)->examples($template, 'en');
        $texts = array_column(array_merge(...$examples['rows']), 'text');

        expect($texts)->toContain('not a moment')->toContain('not a day');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'A moment') && $context['value'] === 'not a moment' && $context['locale'] === 'en' && $context['exception'] instanceof Throwable)->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'A day') && $context['value'] === 'not a day')->once();
    });

    it('writes a moment and a day it can format without a warning', function (): void {
        Log::spy();

        $this->get('/en/templates/sample-records')->assertOk();

        Log::shouldNotHaveReceived('warning');
    });

    it('has a button that uses the template, at the top and at the end', function (): void {
        $page = templateDom($this->get('/templates/sample-tools')->assertOk());

        expect(templateAttributes($page, '//a[contains(@href, "/app/templates/")]', 'href'))->toBe(['/app/templates/sample-tools', '/app/templates/sample-tools'])
            ->and(templateTexts($page, '//a[contains(@href, "/app/templates/")]'))->toBe(['Utiliser ce modèle', 'Utiliser ce modèle']);
    });

    it('has a breadcrumb back to the gallery and the home page', function (): void {
        $page = templateDom($this->get('/en/templates/sample-tools')->assertOk());

        expect(templateTexts($page, '//ol[contains(@class, "breadcrumb")]/li'))->toBe(['Home', 'Inventory templates', 'Tools'])
            ->and(templateAttributes($page, '//ol[contains(@class, "breadcrumb")]//a', 'href'))->toBe(['/', '/en/templates']);
    });

    it('is a 404 for a template that does not exist', function (string $path): void {
        $this->get($path)->assertNotFound();
    })->with(['/templates/nothing', '/en/templates/nothing', '/templates/SAMPLE-TOOLS', '/templates/sample-tools.json', '/templates/sample-tools/extra']);

    it('escapes whatever a file says', function (): void {
        $folder = templateFolder();
        $data = templateData('sample-tools');
        $data['name']['fr'] = 'Stock < 5 & "plus" \'vite\'';
        $data['summary']['fr'] = 'Moins de <5 articles';
        $data['fields'][1]['name']['fr'] = 'Marque & modèle';
        file_put_contents($folder.'/risky.json', json_encode($data));
        templateCatalogueFrom($folder);

        $body = $this->get('/templates/risky')->assertOk()->getContent();

        expect($body)->toContain('Stock &lt; 5 &amp; &quot;plus&quot; &#039;vite&#039;')
            ->and($body)->toContain('Moins de &lt;5 articles')
            ->and($body)->toContain('Marque &amp; modèle')
            ->and($body)->not->toContain('Moins de <5');
    });
});

describe('the markup', function (): void {
    it('never uses x-html or unescaped output', function (string $path): void {
        $body = $this->get($path)->assertOk()->getContent();

        expect($body)->not->toContain('x-html')->and($body)->not->toContain('v-html');
    })->with(['/templates', '/en/templates', '/templates/sample-home', '/en/templates/sample-shop']);

    it('has no x-html and no raw echo in the views of the public pages', function (): void {
        foreach (['templates/index', 'templates/show', 'components/locale-links'] as $view) {
            $source = (string) file_get_contents(resource_path("views/{$view}.blade.php"));

            expect($source)->not->toContain('x-html')->and($source)->not->toContain('{!!');
        }
    });

    it('gives no icon a class attribute of its own', function (): void {
        foreach (['templates/index', 'templates/show', 'components/locale-links', 'layouts/app'] as $view) {
            expect((string) file_get_contents(resource_path("views/{$view}.blade.php")))->not->toMatch('/<x-tabler-[a-z0-9-]+[^>]*\sclass=/');
        }
    });
});

describe('the addresses of the public pages', function (): void {
    it('are served', function (string $locale): void {
        foreach (app(TemplateCatalogue::class)->all() as $template) {
            $path = PublicUrls::templatePath($template->slug, $locale);

            $this->get($path)->assertOk();
            expect(PublicUrls::template($template->slug, $locale))->toBe('http://localhost'.$path);
        }

        $this->get(PublicUrls::templatesPath($locale))->assertOk();
        expect(PublicUrls::templates($locale))->toBe('http://localhost'.PublicUrls::templatesPath($locale));
    })->with(['fr', 'en']);

    it('follow the language of the instance', function (): void {
        config(['app.default_locale' => 'en']);

        expect(PublicUrls::templatesPath('en'))->toBe('/templates')
            ->and(PublicUrls::templatesPath('fr'))->toBe('/fr/templates')
            ->and(PublicUrls::template('sample-tools', 'fr'))->toBe('http://localhost/fr/templates/sample-tools');
    });

    it('start from APP_URL, without a trailing slash', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        expect(PublicUrls::home())->toBe('https://inventaire.example/')
            ->and(PublicUrls::templates('en'))->toBe('https://inventaire.example/en/templates');
    });

    it('encode the slug', function (): void {
        expect(PublicUrls::templatePath('a b/c', 'fr'))->toBe('/templates/a%20b%2Fc');
    });
});

describe('using a template', function (): void {
    it('sends a visitor who is not signed in to the login page, and back after signing in', function (): void {
        $this->get('/app/templates/sample-tools')->assertRedirect(route('login'));

        expect(session('url.intended'))->toBe('http://localhost/app/templates/sample-tools');

        // Once signed in, the login page sends the person to /app, which takes them back to where they were going.
        $person = inventoryPerson();

        $this->actingAs($person)->get('/app')->assertRedirect('http://localhost/app/templates/sample-tools');
        $this->get('/app')->assertRedirect(route('app.workspace', ['workspace' => $person->workspaces()->sole()->ulid]));
    });

    it('takes a person back to the page of the account they were sent from, once, whatever the query', function (string $intended, string $to): void {
        $person = inventoryPerson();

        $this->actingAs($person)->withSession(['url.intended' => $intended])->get('/app')->assertRedirect($to);

        // Used once.
        $this->get('/app')->assertRedirect(route('app.workspace', ['workspace' => $person->workspaces()->sole()->ulid]));
    })->with([
        ['http://localhost/app/templates/sample-tools', 'http://localhost/app/templates/sample-tools'],
        ['http://localhost/app/account?tab=export', 'http://localhost/app/account?tab=export'],
        ['http://LOCALHOST:80/app/workspaces#top', 'http://localhost/app/workspaces'],
        ['http://localhost/app/w/01HZ/inventories/01J', 'http://localhost/app/w/01HZ/inventories/01J'],
    ]);

    it('forgets a stale place that is not a page of the account, and lands in the workspace', function (string $intended): void {
        $person = inventoryPerson();
        $workspace = route('app.workspace', ['workspace' => $person->workspaces()->sole()->ulid]);

        $this->actingAs($person)->withSession(['url.intended' => $intended])->get('/app')->assertRedirect($workspace);

        expect(session()->has('url.intended'))->toBeFalse();
    })->with([
        'a public page' => 'http://localhost/templates/sample-tools',
        'the login page' => 'http://localhost/login',
        'the home page' => 'http://localhost/',
        'an API address' => 'http://localhost/api/v1/templates',
        'this very address, which would loop' => 'http://localhost/app',
        'this very address with a slash' => 'http://localhost/app/',
        'a path that only starts like it' => 'http://localhost/apple/pie',
        'a page of another site' => 'https://evil.example/app/templates/sample-tools',
        'a page of another port' => 'http://localhost:8080/app/account',
        'another scheme' => 'https://localhost/app/account',
        'a host that only starts like the instance' => 'http://localhost.evil.example/app/account',
        'a user name that looks like the instance' => 'http://localhost@evil.example/app/account',
        'an address without a host' => '//evil.example/app/account',
        'a path without a host' => '/app/account',
        'a way out of /app' => 'http://localhost/app/../login',
        'a way out of /app, encoded' => 'http://localhost/app/%2e%2e/login',
        'a back slash' => 'http://localhost/app\\@evil.example/',
        'something that is not an address' => 'javascript:alert(1)',
        'nothing but spaces' => ' ',
    ]);

    it('lands in the workspace of an instance whose address is not the one of the request, when the place was written for the other', function (): void {
        config(['app.url' => 'https://inventaire.example']);
        $person = inventoryPerson();

        $this->actingAs($person)->withSession(['url.intended' => 'http://localhost/app/account'])->get('/app')
            ->assertRedirect(route('app.workspace', ['workspace' => $person->workspaces()->sole()->ulid]));
    });

    it('builds the address of the page from APP_URL', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        $this->actingAs(inventoryPerson())->withSession(['url.intended' => 'https://INVENTAIRE.example/app/account?x=1'])->get('/app')
            ->assertRedirect('https://inventaire.example/app/account?x=1');
    });

    it('opens the dialog of a new inventory in the workspace the person works in', function (): void {
        $person = inventoryPerson();
        $workspace = $person->workspaces()->sole();

        $this->actingAs($person)->get('/app/templates/sample-tools')
            ->assertRedirect("/app/w/{$workspace->ulid}?template=sample-tools");
    });

    it('prefers the current workspace when the person may edit it, else the first they may edit', function (): void {
        $team = inventoryTeam();
        $editor = $team['editor'];
        $personal = $editor->workspaces()->where('workspaces.id', '!=', $team['workspace']->id)->sole();

        // The current workspace is the shared one, where the person edits.
        app(Workspaces::class)->makeCurrent($editor, $team['workspace']);
        $this->actingAs($editor)->get('/app/templates/sample-tools')->assertRedirect("/app/w/{$team['workspace']->ulid}?template=sample-tools");

        app(Workspaces::class)->makeCurrent($editor, $personal);
        $this->actingAs($editor)->get('/app/templates/sample-tools')->assertRedirect("/app/w/{$personal->ulid}?template=sample-tools");
    });

    it('skips the workspaces where the person may only look', function (): void {
        $team = inventoryTeam();
        $viewer = $team['viewer'];
        $personal = $viewer->workspaces()->where('workspaces.id', '!=', $team['workspace']->id)->sole();
        app(Workspaces::class)->makeCurrent($viewer, $team['workspace']);

        $this->actingAs($viewer)->get('/app/templates/sample-tools')->assertRedirect("/app/w/{$personal->ulid}?template=sample-tools");
    });

    it('sends a person who may edit nowhere to their workspaces, with a notice', function (): void {
        $team = inventoryTeam();
        $viewer = $team['viewer'];
        $personal = $viewer->workspaces()->where('workspaces.id', '!=', $team['workspace']->id)->sole();
        $viewer->workspaces()->detach($personal->id);

        $this->actingAs($viewer)->get('/app/templates/sample-tools')->assertRedirect('/app/workspaces')->assertSessionHas('template_needs_workspace');

        $this->get('/app/workspaces', ['Accept-Language' => 'fr'])->assertOk()->assertSee('Il vous faut un espace de travail où vous pouvez ajouter des inventaires pour utiliser un modèle.', false);
        // Said once.
        $this->get('/app/workspaces', ['Accept-Language' => 'fr'])->assertOk()->assertDontSee('Il vous faut un espace de travail');
    });

    it('shows the notice, as a warning, on the page of the workspaces the person is sent to, and only once', function (): void {
        $team = inventoryTeam();
        $viewer = $team['viewer'];
        $viewer->workspaces()->detach($viewer->workspaces()->where('workspaces.id', '!=', $team['workspace']->id)->sole()->id);

        $page = templateDom($this->actingAs($viewer)->followingRedirects()->get('/app/templates/sample-tools', ['Accept-Language' => 'en'])->assertOk());

        expect(templateTexts($page, '//h1'))->toBe(['Workspaces'])
            ->and(templateAttributes($page, '//div[@x-data="flashNotice"]', 'data-type'))->toBe(['warning'])
            ->and(templateAttributes($page, '//div[@x-data="flashNotice"]', 'data-message'))->toBe(['You need a workspace where you can add inventories to use a template.']);

        $again = templateDom($this->get('/app/workspaces', ['Accept-Language' => 'en'])->assertOk());

        expect($again->query('//div[@x-data="flashNotice"]')->length)->toBe(0);
    });

    it('is a 404 for a template that does not exist', function (): void {
        $this->actingAs(inventoryPerson())->get('/app/templates/nothing')->assertNotFound();
    });
});

describe('the shipped templates', function (): void {
    it('all have a page in every language', function (): void {
        templateCatalogueFrom(base_path('inventory-templates'));

        foreach (app(TemplateCatalogue::class)->all() as $template) {
            foreach (['fr', 'en'] as $locale) {
                $page = templateDom($this->get(PublicUrls::templatePath($template->slug, $locale))->assertOk());

                expect(templateTexts($page, '//h1'))->toBe([$template->name($locale)])
                    ->and($page->query('(//table)[2]/tbody/tr')->length)->toBeGreaterThanOrEqual(2);
            }
        }

        $this->get('/templates')->assertOk();
        $this->get('/en/templates')->assertOk();
    });
});
