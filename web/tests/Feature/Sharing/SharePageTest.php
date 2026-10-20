<?php

declare(strict_types=1);

use App\Inventories\ItemSearchText;
use App\Inventories\Sharing\ShareLinks;
use App\Models\Item;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../../Support/sharing.php';
require_once __DIR__.'/../../Support/attachments.php';

uses(RefreshDatabase::class);

/*
 * The public page of a share link (`/s/{token}`): an inventory read-only for anybody who holds the address. What it shows and never
 * shows, how it answers when the address opens nothing, the headers that keep robots and caches away, that it keeps no session, how
 * it is paged and searched, and that it is limited in pace. The photos and files it links to are in ShareFilesTest.
 */

/**
 * The item of the fixture that has a value everywhere, with the values of the fields changed (forced: the checks of values are skipped,
 * to store what the page must still survive).
 *
 * @param  array<string, mixed>  $byField  The new value by key of the fixture's fields.
 */
function sharePageForce(array $case, array $byField): Item
{
    $item = $case['items']['drill'];
    $values = $item->values;

    foreach ($byField as $key => $value) {
        $values[$case['fields'][$key]->ulid] = $value;
    }

    $item->forceFill(['values' => $values])->save();

    return $item->refresh();
}

/**
 * The texts of the cells of the row of an item of the fixture, by name of column.
 *
 * @return array<string, string>
 */
function sharePageRow(DOMXPath $page, int $row): array
{
    $headers = shareTexts($page, '//thead//th');
    $cells = shareTexts($page, '//tbody/tr['.$row.']/td');

    return array_combine($headers, $cells);
}

describe('the content of the page', function (): void {
    it('shows the name and the description of the inventory, how many items it holds and when the link expires', function (): void {
        $case = shareFixture();

        $response = sharePageGet($this, $case['token'])->assertOk();
        $page = shareDom($response);

        expect(shareTexts($page, '//h1'))->toBe(['Office equipment'])
            // The description is text: its markup is shown, not run.
            ->and(shareTexts($page, '//h1/following-sibling::p[1]'))->toBe(['Everything <b>we</b> own'])
            ->and($response->getContent())->not->toContain('<b>we</b>')
            ->and(shareTexts($page, '//h1/../p[last()]')[0])->toStartWith('3 items')->toContain('This link expires on')
            ->and(shareTexts($page, '//title'))->toBe(['Office equipment · '.config('app.name')])
            ->and($response->headers->get('Content-Language'))->toBe('en');
    });

    it('says nothing of the expiry of a link that never expires', function (): void {
        $case = shareFixture(days: null);

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect(shareTexts($page, '//h1/../p[last()]')[0])->toBe('3 items');
    });

    it('has a column for each field and a row for each item, the newest first', function (): void {
        $case = shareFixture();

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect(shareTexts($page, '//thead//th'))->toBe([
            'Name', 'Notes', 'Quantity (kg)', 'Price (EUR)', 'Discount (%)', 'In stock', 'Bought', 'Seen', 'Opening',
            'Condition', 'Labels', 'Keywords', 'Website', 'Contact', 'Phone', 'Grade', 'Color', 'Barcode', 'Photos', 'Docs',
        ])
            ->and($page->query('//tbody/tr')->length)->toBe(3)
            // The empty item was made last, the one with every value first.
            ->and(sharePageRow($page, 1)['Name'])->toBe('—')
            ->and(sharePageRow($page, 2)['Name'])->toBe('=1+1')
            ->and(sharePageRow($page, 3)['Name'])->toBe('Drill 18V');
    });

    it('writes the values the way the language of the visitor reads them, moments in the timezone of the instance', function (): void {
        $case = shareFixture();

        $row = sharePageRow(shareDom(sharePageGet($this, $case['token'])->assertOk()), 3);

        expect($row)->toMatchArray([
            'Notes' => 'Two lines of notes',
            'Quantity (kg)' => '2.50 kg',
            'Price (EUR)' => '€1,234.50',
            'Discount (%)' => '12.5%',
            'In stock' => 'Yes',
            'Bought' => 'Mar 1, 2026',
            // 10:30 UTC is 12:30 in Paris in July.
            'Seen' => 'Jul 1, 2026, 12:30 PM',
            'Opening' => '07:05',
            'Condition' => 'Used',
            'Labels' => 'Heavy Fragile',
            'Keywords' => 'power cordless',
            'Grade' => '★★★★☆',
            'Color' => '#ff8800',
            'Barcode' => '0012345678905',
        ]);

        $french = sharePageRow(shareDom(sharePageGet($this, $case['token'], headers: ['Accept-Language' => 'fr'])->assertOk()), 3);

        expect($french['In stock'])->toBe('Oui')
            ->and($french['Price (EUR)'])->toBe('1 234,50 €')
            ->and($french['Bought'])->toBe('1 mars 2026')
            ->and($french['Quantity (kg)'])->toBe('2,50 kg');
    });

    it('is translated', function (): void {
        $case = shareFixture();

        $page = shareDom(sharePageGet($this, $case['token'], headers: ['Accept-Language' => 'fr'])->assertOk());

        expect(shareTexts($page, '//html/body//footer'))->toBe(['Partage en lecture seule réalisé avec '.config('app.name')])
            ->and(shareTexts($page, '//h1/../p[last()]')[0])->toStartWith('3 articles')->toContain('Ce lien expire le')
            ->and(shareAttributes($page, '//html', 'lang'))->toBe(['fr']);
    });

    it('draws a choice or a tag as a badge, a rating as stars with their grade in words and a color as a swatch', function (): void {
        $case = shareFixture();

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());
        $cells = '//tbody/tr[3]/td';

        expect(shareTexts($page, $cells.'[10]//span[contains(@class, "badge")]'))->toBe(['Used'])
            ->and(shareTexts($page, $cells.'[11]//span[contains(@class, "badge")]'))->toBe(['Heavy', 'Fragile'])
            ->and(shareTexts($page, $cells.'[12]//span[contains(@class, "badge")]'))->toBe(['power', 'cordless'])
            ->and(shareAttributes($page, $cells.'[16]//span[@role="img"]', 'aria-label'))->toBe(['4 out of 5'])
            ->and(shareAttributes($page, $cells.'[17]//span[contains(@class, "app-swatch")]', 'style'))->toBe(['background-color: #ff8800'])
            ->and(shareAttributes($page, $cells.'[18]/span[contains(@class, "font-monospace")]', 'class'))->toHaveCount(1);
    });

    it('colors the badge of a choice with the color of the choice', function (): void {
        $case = shareFixture();
        $field = $case['fields']['condition'];
        $options = $field->options;
        $options['choices'][1]['color'] = 'red';
        $field->forceFill(['options' => $options])->save();

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect(shareAttributes($page, '//tbody/tr[3]/td[10]//span[contains(@class, "badge")]', 'class'))->toBe(['badge bg-red-lt']);
    });

    it('leaves out a choice the field no longer has', function (): void {
        $case = shareFixture();
        $field = $case['fields']['labels'];
        $options = $field->options;
        $options['choices'] = array_values(array_filter($options['choices'], fn (array $choice): bool => $choice['label'] === 'Heavy'));
        $field->forceFill(['options' => $options])->save();

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect(shareTexts($page, '//tbody/tr[3]/td[11]//span[contains(@class, "badge")]'))->toBe(['Heavy']);
    });

    it('shows the page of an inventory with no item, and of one with no field', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        $page = shareDom(sharePageGet($this, $token)->assertOk());

        expect(shareTexts($page, '//div[@class="card"]/div[contains(@class, "card-body")]'))->toBe(['No item yet'])
            ->and($page->query('//table')->length)->toBe(0);

        itemIn($case['inventory']);

        // An item with nothing to show: a table with a row and no cell.
        $again = shareDom(sharePageGet($this, $token)->assertOk());

        expect($again->query('//tbody/tr')->length)->toBe(1);
    });
});

describe('what the page never shows', function (): void {
    it('says nothing of the workspace, its members, who made the items or who uploaded the files', function (): void {
        $case = shareFixture();
        $uploader = inventoryPerson('Zelda Uploader');
        attachmentTo($case['inventory'], $case['items']['drill'], attachmentImage('front.png'), $uploader, $case['fields']['photos']);
        attachmentTo($case['inventory'], $case['items']['drill'], attachmentPdf('invoice.pdf'), $uploader, $case['fields']['docs']);

        $content = sharePageGet($this, $case['token'])->assertOk()->getContent();

        // The workspace is called Shop; its members are Owner, Editor and Viewer; the person who made the link is Owner too.
        foreach (['Shop', 'Owner', 'Editor', 'Viewer', 'Zelda', 'Uploader', 'created_by', 'uploaded_by', 'updated_by', 'workspace'] as $word) {
            expect($content)->not->toContain($word);
        }

        // Nor who created the link, its label (the owner's note) or other inventories.
        expect($content)->not->toContain('Insurer')
            ->and($content)->not->toContain($case['team']['owner']->email);
    });

    it('does not show another inventory of the workspace', function (): void {
        $case = shareFixture();
        $other = inventoryIn($case['team']['workspace'], 'Secret plans');
        itemIn($other);

        expect(sharePageGet($this, $case['token'])->assertOk()->getContent())->not->toContain('Secret plans');
    });

    it('has no sort, no filter and no total: only a page and a search', function (): void {
        $case = shareFixture();

        $page = shareDom(sharePageGet($this, $case['token'], '?sort=name&direction=desc&filter[name][eq]=Drill&totals[]=x')->assertOk());

        // The order is still the newest first, and nothing is filtered out.
        expect(sharePageRow($page, 3)['Name'])->toBe('Drill 18V')
            ->and($page->query('//tbody/tr')->length)->toBe(3)
            ->and($page->query('//thead//a | //thead//button')->length)->toBe(0)
            ->and($page->query('//tfoot')->length)->toBe(0);
    });
});

describe('searching and paging', function (): void {
    it('narrows the list to the items that hold every word, whatever the case and the accents', function (): void {
        $case = shareFixture();
        sharePageForce($case, ['name' => 'Perceuse Électrique']);
        // The text the items are searched by is rebuilt when a value changes through the application.
        app(ItemSearchText::class)->refresh($case['inventory'], $case['inventory']->fields()->get());

        $page = shareDom(sharePageGet($this, $case['token'], '?q=electrique+PERCEUSE')->assertOk());

        expect($page->query('//tbody/tr')->length)->toBe(1)
            ->and(shareTexts($page, '//tbody/tr[1]/td[1]'))->toBe(['Perceuse Électrique'])
            ->and(shareAttributes($page, '//input[@name="q"]', 'value'))->toBe(['electrique PERCEUSE'])
            ->and(shareTexts($page, '//p[@role="status"]'))->toBe(['1 result out of 3'])
            // The form can be cleared.
            ->and(shareTexts($page, '//form//a'))->toBe(['Clear the search']);
    });

    it('says when nothing matches', function (): void {
        $case = shareFixture();

        $page = shareDom(sharePageGet($this, $case['token'], '?q=nothing+like+this')->assertOk());

        expect(shareTexts($page, '//div[@class="card"]/div[contains(@class, "card-body")]'))->toBe(['No item matches your search.'])
            ->and(shareTexts($page, '//p[@role="status"]'))->toBe(['0 results out of 3']);
    });

    it('takes the wildcards of a search for plain characters, and no more than 100 characters of it', function (): void {
        $case = shareFixture();

        $wild = shareDom(sharePageGet($this, $case['token'], '?q=%25')->assertOk());
        $long = shareDom(sharePageGet($this, $case['token'], '?q='.urlencode(str_repeat('é', 150)))->assertOk());

        expect($wild->query('//tbody/tr')->length)->toBe(0)
            ->and(mb_strlen(shareAttributes($long, '//input[@name="q"]', 'value')[0]))->toBe(100);
    });

    it('ignores a search that is not a text, and one that is only white space', function (): void {
        $case = shareFixture();

        foreach (['?q[]=Drill', '?q=%20%20', '?q='] as $query) {
            expect(shareDom(sharePageGet($this, $case['token'], $query)->assertOk())->query('//tbody/tr')->length)->toBe(3);
        }
    });

    it('survives a search that is not valid UTF-8', function (): void {
        $case = shareFixture();

        sharePageGet($this, $case['token'], '?q=%FF%FEdrill')->assertOk();
    });

    it('pages the items 50 at a time and keeps the search in the links of the pages', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner'], days: null);
        $field = fieldIn($case['inventory'], 'text', 'Name');
        Item::factory()->count(120)->for($case['inventory'])->sequence(fn ($sequence) => ['values' => [$field->ulid => 'Box '.str_pad((string) $sequence->index, 3, '0', STR_PAD_LEFT)]])->create();

        $first = shareDom(sharePageGet($this, $token)->assertOk());
        $second = shareDom(sharePageGet($this, $token, '?page=2')->assertOk());
        $third = shareDom(sharePageGet($this, $token, '?page=3&q=box')->assertOk());

        expect($first->query('//tbody/tr')->length)->toBe(50)
            // Newest first: the last one made is the first one shown.
            ->and(shareTexts($first, '//tbody/tr[1]/td[1]'))->toBe(['Box 119'])
            ->and(shareTexts($second, '//tbody/tr[1]/td[1]'))->toBe(['Box 069'])
            ->and($second->query('//tbody/tr')->length)->toBe(50)
            ->and($third->query('//tbody/tr')->length)->toBe(20)
            ->and(shareTexts($first, '//div[contains(@class, "card-footer")]/span'))->toBe(['1–50 of 120'])
            ->and(shareTexts($third, '//div[contains(@class, "card-footer")]/span'))->toBe(['101–120 of 120'])
            ->and(shareTexts($first, '//ul[contains(@class, "pagination")]/li'))->toBe(['Previous', 'Page 1 / 3', 'Next'])
            // The links are relative paths of the link, never an absolute address built from the Host header.
            ->and(shareAttributes($first, '//a[@rel="next"]', 'href'))->toBe(['/s/'.$token.'?page=2'])
            ->and(shareAttributes($second, '//a[@rel="prev"]', 'href'))->toBe(['/s/'.$token])
            ->and(shareAttributes($second, '//a[@rel="next"]', 'href'))->toBe(['/s/'.$token.'?page=3'])
            ->and(shareAttributes($third, '//a[@rel="prev"]', 'href'))->toBe(['/s/'.$token.'?q=box&page=2'])
            ->and($third->query('//a[@rel="next"]')->length)->toBe(0);
    });

    it('shows the last page for a page beyond it, and the first for a page that is not a number', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);
        Item::factory()->count(60)->for($case['inventory'])->create();

        $beyond = shareDom(sharePageGet($this, $token, '?page=99999999999999999999')->assertOk());
        $big = shareDom(sharePageGet($this, $token, '?page=1000000')->assertOk());

        expect(shareTexts($beyond, '//ul[contains(@class, "pagination")]/li[2]'))->toBe(['Page 2 / 2'])
            ->and($big->query('//tbody/tr')->length)->toBe(10);

        foreach (['?page=abc', '?page=-3', '?page=0', '?page[]=2', '?page=1.5'] as $query) {
            expect(shareTexts(shareDom(sharePageGet($this, $token, $query)->assertOk()), '//ul[contains(@class, "pagination")]/li[2]'))->toBe(['Page 1 / 2']);
        }
    });

    it('shows no pager for a single page', function (): void {
        $case = shareFixture();

        expect(shareDom(sharePageGet($this, $case['token'])->assertOk())->query('//ul[contains(@class, "pagination")]')->length)->toBe(0);
    });

    it('reads the items and their files in a number of queries that does not grow with the page', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);
        attachmentDisk();
        $photos = fieldIn($case['inventory'], 'photo', 'Photos', ['max_files' => 3]);
        $items = Item::factory()->count(10)->for($case['inventory'])->create();

        foreach ($items->take(5) as $item) {
            attachmentTo($case['inventory'], $item, attachmentImage(), null, $photos);
        }

        // The first request of the process reads what the next ones find ready.
        sharePageGet($this, $token)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        sharePageGet($this, $token)->assertOk();
        $few = count(DB::getQueryLog());

        Item::factory()->count(30)->for($case['inventory'])->create();
        DB::flushQueryLog();
        sharePageGet($this, $token)->assertOk();
        $many = count(DB::getQueryLog());

        expect($many)->toBe($few);
    });
});

describe('an address that opens nothing', function (): void {
    it('is the same 404 page whatever the reason: malformed, unknown, expired, revoked, or sharing turned off', function (): void {
        $case = shareFixture();
        $answer = function (string $token): array {
            $response = sharePageGet($this, $token);

            return [
                'status' => $response->getStatusCode(),
                'content' => $response->getContent(),
                'type' => $response->headers->get('Content-Type'),
                'robots' => $response->headers->get('X-Robots-Tag'),
                'cache' => $response->headers->get('Cache-Control'),
                'vary' => $response->headers->get('Vary'),
                'cookies' => $response->headers->getCookies(),
            ];
        };

        ['link' => $expired, 'token' => $expiredToken] = shareLinkMake($case['inventory'], $case['team']['owner']);
        $expired->forceFill(['expires_at' => now()->subSecond()])->save();
        ['link' => $revoked, 'token' => $revokedToken] = shareLinkMake($case['inventory'], $case['team']['owner']);
        app(ShareLinks::class)->revoke($case['inventory'], $revoked);

        // The link of the fixture works until sharing is turned off, the last of the answers.
        sharePageGet($this, $case['token'])->assertOk();

        $answers = collect([
            'a text that is not shaped like a token' => $answer('not-a-token'),
            'a token that is too long' => $answer(ShareLinks::newToken().'A'),
            'a token nobody made' => $answer(ShareLinks::newToken()),
            'an expired link' => $answer($expiredToken),
            'a revoked link' => $answer($revokedToken),
        ]);

        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);
        $answers['sharing turned off'] = $answer($case['token']);

        expect($answers->pluck('status')->unique()->all())->toBe([404])
            ->and($answers->pluck('content')->unique())->toHaveCount(1)
            ->and($answers->pluck('type')->unique())->toHaveCount(1)
            ->and($answers->pluck('robots')->unique()->all())->toBe(['noindex'])
            ->and($answers->pluck('cache')->unique())->toHaveCount(1)
            ->and($answers->pluck('vary')->unique())->toHaveCount(1)
            ->and($answers->pluck('cookies')->flatten()->all())->toBe([])
            // It says nothing of the inventory, or of what a link is.
            ->and($answers->first()['content'])->not->toContain('Office equipment')->not->toContain('share');
    });

    it('does not note the use of a link that does not work, nor touch the database for an address that is not shaped like a token', function (): void {
        $case = shareFixture();
        $case['link']->forceFill(['expires_at' => now()->subSecond()])->save();

        sharePageGet($this, $case['token'])->assertNotFound();
        DB::enableQueryLog();
        sharePageGet($this, 'short')->assertNotFound();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($case['link']->refresh()->last_used_at)->toBeNull()
            ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'share_links'))->all())->toBe([]);
    });

    it('works again when sharing is turned back on', function (): void {
        $case = shareFixture();
        $settings = app(InstanceSettings::class);

        $settings->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);
        sharePageGet($this, $case['token'])->assertNotFound();

        $settings->update([InstanceSettings::SHARE_LINKS_ENABLED => true]);
        sharePageGet($this, $case['token'])->assertOk();
    });

    it('is not found for the address of a link of an inventory that was deleted', function (): void {
        $case = shareFixture();
        $case['inventory']->delete();

        sharePageGet($this, $case['token'])->assertNotFound();
    });
});

describe('the headers of the page', function (): void {
    it('asks robots not to index it nor to follow its links, in the header and in the page', function (): void {
        $case = shareFixture();

        $response = sharePageGet($this, $case['token'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $page = shareDom($response);

        expect(shareAttributes($page, '//meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow']);
    });

    it('asks robots not to index the page even on an instance that allows indexing', function (): void {
        config(['app.indexable' => true]);
        $case = shareFixture();

        $response = sharePageGet($this, $case['token'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        expect(shareAttributes(shareDom($response), '//meta[@name="robots"]', 'content'))->toBe(['noindex, nofollow']);
    });

    it('keeps the address out of the head: no canonical address, no social card, no alternate language, no description', function (): void {
        config(['app.indexable' => true]);
        $case = shareFixture();

        $head = substr($content = sharePageGet($this, $case['token'])->assertOk()->getContent(), 0, (int) strpos($content, '</head>'));

        expect($head)->not->toContain($case['token'])
            ->and($head)->not->toContain('canonical')
            ->and($head)->not->toContain('og:')
            ->and($head)->not->toContain('hreflang')
            ->and($head)->not->toContain('name="description"')
            ->and($head)->not->toContain('csrf');
    });

    it('is never kept by a cache, and says it depends on the language of the visitor', function (): void {
        $case = shareFixture();

        $response = sharePageGet($this, $case['token'])->assertOk();

        expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
            ->and($response->headers->getCacheControlDirective('no-store'))->toBeTrue()
            ->and($response->headers->getCacheControlDirective('private'))->toBeTrue()
            ->and($response->getVary())->toContain('Accept-Language')->toContain('Cookie');
    });

    it('does not leak the address through the Referer: links out of the page carry no referrer, and the policy of the site keeps it inside', function (): void {
        $case = shareFixture();

        $response = sharePageGet($this, $case['token'])->assertOk()->assertHeader('Referrer-Policy', 'same-origin');
        $page = shareDom($response);

        // Every link that leaves the page to another site says so with noreferrer.
        foreach ($page->query('//a[starts-with(@href, "http")]') as $link) {
            expect($link->getAttribute('rel'))->toContain('noreferrer')->toContain('noopener')->toContain('nofollow');
        }

        expect($page->query('//a[starts-with(@href, "http")]')->length)->toBeGreaterThan(0);
    });

    it('loads no script and no resource from another host', function (): void {
        $case = shareFixture();

        $response = sharePageGet($this, $case['token'])->assertOk();
        $content = $response->getContent();
        $page = shareDom($response);

        expect($page->query('//script')->length)->toBe(0)
            ->and($content)->not->toContain('x-data')
            ->and($content)->not->toContain('@click');

        foreach ($page->query('//link[@href] | //img[@src]') as $element) {
            $address = $element->getAttribute('href') ?: $element->getAttribute('src');

            expect(parse_url($address, PHP_URL_HOST))->toBeIn([null, 'localhost']);
        }
    });

    it('scrolls the table inside its own container, so that the page itself never overflows', function (): void {
        $case = shareFixture();

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect($page->query('//div[contains(@class, "table-responsive")]/table')->length)->toBe(1)
            // Reachable and named for the keyboard and for screen readers.
            ->and(shareAttributes($page, '//div[contains(@class, "table-responsive")]', 'tabindex'))->toBe(['0'])
            ->and(shareAttributes($page, '//div[contains(@class, "table-responsive")]', 'role'))->toBe(['region'])
            ->and(shareAttributes($page, '//meta[@name="viewport"]', 'content'))->toBe(['width=device-width, initial-scale=1, viewport-fit=cover']);
    });
});

describe('no session', function (): void {
    it('sends no cookie and starts no session, for the page and for a page that does not exist', function (): void {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $case = shareFixture();

        foreach ([$case['token'], 'not-a-token', ShareLinks::newToken()] as $token) {
            $response = sharePageGet($this, $token);

            expect($response->headers->getCookies())->toBe([])
                ->and($response->headers->has('Set-Cookie'))->toBeFalse();
        }

        expect(DB::table('sessions')->count())->toBe(0)
            ->and(app('session')->driver()->isStarted())->toBeFalse();

        // The harness: a page that has a session writes a row and sends the cookie.
        $login = $this->get('/login');

        expect(DB::table('sessions')->count())->toBe(1)
            ->and($login->headers->getCookies())->not->toBe([]);
    });

    it('does not write the language of the visitor in a cookie', function (): void {
        $case = shareFixture();

        $response = sharePageGet($this, $case['token'], headers: ['Accept-Language' => 'fr'])->assertOk();

        expect($response->headers->getCookies())->toBe([]);
    });

    it('does not ask for a CSRF token: a page that only reads has none to send', function (): void {
        $case = shareFixture();

        $content = sharePageGet($this, $case['token'])->assertOk()->getContent();

        expect($content)->not->toContain('csrf-token')->not->toContain('_token');
    });
});

describe('the use of a link', function (): void {
    it('notes the opening of the link, at most once an hour', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareFixture();
        $link = $case['link'];

        expect($link->refresh()->last_used_at)->toBeNull();

        sharePageGet($this, $case['token'])->assertOk();
        $first = $link->refresh()->last_used_at;

        $this->travel(30)->minutes();
        sharePageGet($this, $case['token'])->assertOk();

        expect($first)->not->toBeNull()
            ->and($link->refresh()->last_used_at->equalTo($first))->toBeTrue();

        $this->travel(31)->minutes();
        sharePageGet($this, $case['token'])->assertOk();

        expect($link->refresh()->last_used_at->greaterThan($first))->toBeTrue();
    });

    it('does not change the link otherwise', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareFixture();
        $before = $case['link']->refresh()->updated_at;

        $this->travel(2)->hours();
        sharePageGet($this, $case['token'])->assertOk();

        expect($case['link']->refresh()->updated_at->equalTo($before))->toBeTrue();
    });
});

describe('what people typed', function (): void {
    it('is escaped everywhere: the values, the names of the columns, the choices, the tags, the search', function (): void {
        $case = shareFixture();
        $case['fields']['name']->forceFill(['name' => '<script>alert("column")</script>'])->save();
        $field = $case['fields']['condition'];
        $options = $field->options;
        $options['choices'][1]['label'] = '<img src=x onerror=alert("choice")>';
        $field->forceFill(['options' => $options])->save();
        $used = $options['choices'][1]['id'];
        sharePageForce($case, [
            'name' => '<script>alert("name")</script>',
            'notes' => '"><svg onload=alert("notes")>',
            'condition' => $used,
            'keywords' => ['<b onmouseover=alert("tag")>x</b>'],
            'barcode' => '</span><script>alert("barcode")</script>',
        ]);
        $case['inventory']->forceFill(['name' => 'Name <script>alert("inventory")</script>'])->save();

        $response = sharePageGet($this, $case['token'], '?q='.urlencode('"><script>alert("q")</script>'))->assertOk();
        $everything = sharePageGet($this, $case['token'])->assertOk();

        foreach ([$response, $everything] as $answer) {
            $page = shareDom($answer);

            expect($answer->getContent())->not->toContain('<script>alert')
                ->and($page->query('//script')->length)->toBe(0)
                ->and($page->query('//img[@onerror] | //svg[@onload] | //*[@onmouseover]')->length)->toBe(0);
        }

        $page = shareDom($everything);

        // Shown as what they are: text.
        expect(shareTexts($page, '//tbody/tr[3]/td[1]'))->toBe(['<script>alert("name")</script>'])
            ->and(shareTexts($page, '//thead/tr/th[1]'))->toBe(['<script>alert("column")</script>'])
            ->and(shareTexts($page, '//tbody/tr[3]/td[10]//span[contains(@class, "badge")]'))->toBe(['<img src=x onerror=alert("choice")>'])
            ->and(shareTexts($page, '//h1'))->toBe(['Name <script>alert("inventory")</script>']);
    });

    it('links a web address, escaped, and shows anything that is not one as text', function (string $stored, bool $linked): void {
        $case = shareFixture();
        sharePageForce($case, ['website' => $stored]);

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());
        $links = shareAttributes($page, '//tbody/tr[3]/td[13]//a', 'href');

        expect($links)->toBe($linked ? [$stored] : [])
            ->and(shareTexts($page, '//tbody/tr[3]/td[13]'))->toBe([trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $stored))])
            ->and($page->query('//a[@onmouseover]')->length)->toBe(0);

        if ($linked) {
            expect(shareAttributes($page, '//tbody/tr[3]/td[13]//a', 'target'))->toBe(['_blank'])
                ->and(shareAttributes($page, '//tbody/tr[3]/td[13]//a', 'rel'))->toBe(['nofollow noopener noreferrer']);
        }
    })->with([
        'a javascript: address' => ['javascript:alert(document.cookie)', false],
        'a javascript: address in capitals' => ['JAVASCRIPT:alert(1)', false],
        'a javascript: address with white space in the scheme' => ["java\tscript:alert(1)", false],
        'a data: address' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', false],
        'a vbscript: address' => ['vbscript:msgbox(1)', false],
        'a file: address' => ['file:///etc/passwd', false],
        'an address with no scheme' => ['example.com/page', false],
        'an address with white space in it' => ['http://example.com/a b', false],
        'an address with a line break in it' => ["http://example.com/a\nb", false],
        'an address with no host' => ['http:///page', false],
        'a plain https address' => ['https://example.com/a?b=1&c=2', true],
        'an http address in capitals' => ['HTTP://EXAMPLE.COM/Path', true],
        'an address with quotes, which are escaped' => ['http://example.com/"onmouseover="alert(1)', true],
    ]);

    it('links an email address and a phone number only as such', function (): void {
        $case = shareFixture();
        sharePageForce($case, ['contact' => 'a&b#c?d@example.com', 'phone' => '+33 (0)1 23-45.67.89 ext. 5']);

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect(shareAttributes($page, '//tbody/tr[3]/td[14]//a', 'href'))->toBe(['mailto:a%26b%23c%3Fd@example.com'])
            ->and(shareAttributes($page, '//tbody/tr[3]/td[15]//a', 'href'))->toBe(['tel:+3301234567895'])
            ->and(shareTexts($page, '//tbody/tr[3]/td[15]'))->toBe(['+33 (0)1 23-45.67.89 ext. 5']);
    });

    it('shows a phone number with no digit as text', function (): void {
        $case = shareFixture();
        sharePageForce($case, ['phone' => 'call me']);

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect($page->query('//tbody/tr[3]/td[15]//a')->length)->toBe(0)
            ->and(shareTexts($page, '//tbody/tr[3]/td[15]'))->toBe(['call me']);
    });

    it('draws a swatch only for a color of six hexadecimal digits, and never puts anything else in a style', function (): void {
        $case = shareFixture();
        sharePageForce($case, ['color' => 'red;background:url(//evil.example/x)']);

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect($page->query('//*[contains(@class, "app-swatch")]')->length)->toBe(0)
            ->and($page->query('//*[@style]')->length)->toBe(0)
            ->and(shareTexts($page, '//tbody/tr[3]/td[17]'))->toBe(['red;background:url(//evil.example/x)']);
    });

    it('shows the stars of a rating in a field whose maximum is not five, and none for a grade beyond the maximum', function (): void {
        $case = shareFixture();
        $case['fields']['grade']->forceFill(['options' => ['max' => 10]])->save();
        sharePageForce($case, ['grade' => 7]);

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect(shareTexts($page, '//tbody/tr[3]/td[16]'))->toBe(['★★★★★★★☆☆☆'])
            ->and(shareAttributes($page, '//tbody/tr[3]/td[16]//span[@role="img"]', 'aria-label'))->toBe(['7 out of 10']);

        $case['fields']['grade']->forceFill(['options' => ['max' => 3]])->save();

        expect(shareTexts(shareDom(sharePageGet($this, $case['token'])->assertOk()), '//tbody/tr[3]/td[16]'))->toBe(['—']);
    });
});

describe('the pace of the page', function (): void {
    it('is limited for each network and link together, and answers 429 beyond', function (): void {
        config(['inventories.share_page_per_minute' => 3]);
        $case = shareFixture();
        ['token' => $other] = shareLinkMake($case['inventory'], $case['team']['owner']);

        foreach (range(1, 3) as $unused) {
            sharePageGet($this, $case['token'])->assertOk();
        }

        $refused = sharePageGet($this, $case['token'])->assertStatus(429);

        expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)
            // Another link has a count of its own, and so has another network.
            ->and(sharePageGet($this, $other)->getStatusCode())->toBe(200);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/s/'.$case['token'])->assertOk();
    });

    it('counts the addresses that are not shaped like a token together', function (): void {
        config(['inventories.share_page_per_minute' => 2]);

        sharePageGet($this, 'one')->assertNotFound();
        sharePageGet($this, 'two')->assertNotFound();
        sharePageGet($this, 'three')->assertStatus(429);
    });

    it('counts every address a network tries, ten times the pace of a link', function (): void {
        config(['inventories.share_page_per_minute' => 2]);
        $statuses = [];

        foreach (range(1, 21) as $unused) {
            $statuses[] = sharePageGet($this, ShareLinks::newToken())->getStatusCode();
        }

        expect(array_count_values($statuses))->toBe([404 => 20, 429 => 1]);
    });

    it('never writes the token in the keys of the limiters: only its hash', function (): void {
        config(['inventories.share_page_per_minute' => 5, 'inventories.share_files_per_minute' => 7]);
        $token = ShareLinks::newToken();

        foreach (['share-page' => 5, 'share-files' => 7] as $name => $perMinute) {
            $request = Request::create('/s/'.$token, server: ['REMOTE_ADDR' => '198.51.100.7']);
            $request->setRouteResolver(fn () => (new Route('GET', '/s/{token}', []))->bind($request));
            $limits = RateLimiter::limiter($name)($request);

            expect($limits)->toHaveCount(2)
                ->and($limits[0]->maxAttempts)->toBe($perMinute)
                ->and($limits[0]->key)->toBe($name.':198.51.100.7|'.ShareLinks::hash($token))
                ->and($limits[1]->maxAttempts)->toBe($perMinute * 10)
                ->and($limits[1]->key)->toBe($name.'-network:198.51.100.7');

            foreach ($limits as $limit) {
                expect($limit->key)->not->toContain($token);
            }

            $malformed = Request::create('/s/not-a-token', server: ['REMOTE_ADDR' => '198.51.100.7']);
            $malformed->setRouteResolver(fn () => (new Route('GET', '/s/{token}', []))->bind($malformed));

            expect(RateLimiter::limiter($name)($malformed)[0]->key)->toBe($name.':198.51.100.7|malformed');
        }
    });
});

describe('the routes of the public page', function (): void {
    it('are at the paths the links use', function (): void {
        expect(route('share.show', ['token' => 'abc'], absolute: false))->toBe('/s/abc')
            ->and(route('share.attachment', ['token' => 'abc', 'item' => 'i', 'attachment' => 'a'], absolute: false))->toBe('/s/abc/items/i/attachments/a')
            ->and(route('share.thumbnail', ['token' => 'abc', 'item' => 'i', 'attachment' => 'a'], absolute: false))->toBe('/s/abc/items/i/attachments/a/thumbnail')
            ->and(ShareLinks::pagePath('abc'))->toBe(route('share.show', ['token' => 'abc'], absolute: false));
    });

    it('run without the session, the cookies and the CSRF check', function (string $route): void {
        $middleware = Illuminate\Support\Facades\Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [];
        $excluded = Illuminate\Support\Facades\Route::getRoutes()->getByName($route)?->excludedMiddleware() ?? [];

        expect($excluded)->toContain(StartSession::class)
            ->toContain(PreventRequestForgery::class)
            ->and($middleware)->not->toContain('auth');
    })->with(['share.show', 'share.attachment', 'share.thumbnail']);

    it('have no pattern on the token, so that every address gets the 404 page of the application', function (): void {
        foreach (['share.show', 'share.attachment', 'share.thumbnail'] as $name) {
            expect(Illuminate\Support\Facades\Route::getRoutes()->getByName($name)?->wheres)->toBe([]);
        }
    });

    it('only read: other methods are not allowed', function (): void {
        $case = shareFixture();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method}('/s/'.$case['token'])->assertStatus(405);
        }
    });

    it('can be read by a signed-in person like by anybody: the page does not look at who asks', function (): void {
        $case = shareFixture();

        $this->actingAs($case['team']['stranger'])->get('/s/'.$case['token'])->assertOk();
    });
});
