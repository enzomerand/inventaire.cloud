<?php

declare(strict_types=1);

use App\Inventories\Sharing\ShareLinks;
use App\Models\Attachment;
use App\Models\Item;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/sharing.php';
require_once __DIR__.'/../../Support/attachments.php';

uses(RefreshDatabase::class);

/*
 * The photos and files a share link shows (`/s/{token}/items/{item}/attachments/{attachment}` and `.../thumbnail`): what is served, to
 * whom, and what is not, for a link that works only, and never from the cache of a browser for longer than a revocation can wait.
 */

/**
 * The fixture of the public page with a photo and a PDF on its first item.
 *
 * @return array<string, mixed> The fixture, plus `photo`, `pdf` (the attachments) and `drill`.
 */
function shareFilesCase(bool $files = true): array
{
    $case = shareFixture($files);
    $drill = $case['items']['drill'];

    return $case + [
        'drill' => $drill,
        'photo' => attachmentTo($case['inventory'], $drill, attachmentImage('front.png'), null, $case['fields']['photos']),
        'pdf' => attachmentTo($case['inventory'], $drill, attachmentPdf('invoice.pdf'), null, $case['fields']['docs']),
    ];
}

/**
 * The address of a file of an item through a link, or of its thumbnail.
 */
function shareFileUri(string $token, Item $item, Attachment $attachment, bool $thumbnail = false): string
{
    return '/s/'.$token.'/items/'.$item->ulid.'/attachments/'.$attachment->ulid.($thumbnail ? '/thumbnail' : '');
}

describe('the files of a link that shows them', function (): void {
    it('serves the thumbnail of a photo as a small image', function (): void {
        $case = shareFilesCase();

        $response = $this->get(shareFileUri($case['token'], $case['drill'], $case['photo'], true))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and(substr($response->streamedContent(), 0, 3))->toBe("\xFF\xD8\xFF");
    });

    it('serves the file itself, as a download, or shown in the page for an image or a PDF', function (): void {
        $case = shareFilesCase();

        $download = $this->get(shareFileUri($case['token'], $case['drill'], $case['pdf']))->assertOk();
        $inline = $this->get(shareFileUri($case['token'], $case['drill'], $case['pdf']).'?inline=1')->assertOk();

        expect($download->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($download->headers->get('Content-Disposition'))->toStartWith('attachment; filename=invoice.pdf')
            ->and($download->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($download->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($inline->headers->get('Content-Disposition'))->toStartWith('inline; filename=invoice.pdf')
            ->and(substr($download->streamedContent(), 0, 4))->toBe('%PDF');

        // A file that a browser would run is never shown in the page, even when the visitor asks for it.
        $text = attachmentTo($case['inventory'], $case['items']['formulas'], attachmentUploadedAs('notes.html', '<script>alert(1)</script>', 'text/html'), null, $case['fields']['docs']);
        $page = $this->get(shareFileUri($case['token'], $case['items']['formulas'], $text).'?inline=1')->assertOk();

        expect($page->headers->get('Content-Disposition'))->toStartWith('attachment');
    });

    it('answers with a name the visitor cannot turn into a path or a header', function (): void {
        $case = shareFilesCase();
        $odd = attachmentTo($case['inventory'], $case['items']['formulas'], attachmentUploadedAs("a\r\nX-Evil: 1/../é.pdf", '%PDF-1.4', 'application/pdf'), null, $case['fields']['docs']);

        $response = $this->get(shareFileUri($case['token'], $case['items']['formulas'], $odd))->assertOk();

        expect($response->headers->get('X-Evil'))->toBeNull()
            ->and($response->headers->get('Content-Disposition'))->not->toContain("\n");
    });

    it('is revalidated on every request, so that a revoked link serves nothing from a copy: the thumbnail too, which the API lets a browser keep', function (): void {
        $case = shareFilesCase();

        foreach ([true, false] as $thumbnail) {
            $response = $this->get(shareFileUri($case['token'], $case['drill'], $case['photo'], $thumbnail))->assertOk();

            expect($response->headers->getCacheControlDirective('no-cache'))->toBeTrue()
                ->and($response->headers->getCacheControlDirective('private'))->toBeTrue()
                ->and($response->headers->getCacheControlDirective('max-age'))->toBeNull();
        }
    });

    it('lets a browser that has the file ask whether it changed, and says it did not', function (): void {
        $case = shareFilesCase();
        $uri = shareFileUri($case['token'], $case['drill'], $case['pdf']);

        $etag = $this->get($uri)->assertOk()->headers->get('ETag');
        $again = $this->get($uri, ['If-None-Match' => $etag]);

        expect($etag)->toBe('"'.$case['pdf']->ulid.'"');
        $again->assertStatus(304);
    });

    it('stops serving them the moment the link is revoked, even to a browser that has them', function (): void {
        $case = shareFilesCase();
        $file = shareFileUri($case['token'], $case['drill'], $case['pdf']);
        $thumbnail = shareFileUri($case['token'], $case['drill'], $case['photo'], true);
        $etag = $this->get($file)->assertOk()->headers->get('ETag');
        $this->get($thumbnail)->assertOk();

        app(ShareLinks::class)->revoke($case['inventory'], $case['link']);

        $this->get($file)->assertNotFound();
        $this->get($file, ['If-None-Match' => $etag])->assertNotFound();
        $this->get($thumbnail)->assertNotFound();
        sharePageGet($this, $case['token'])->assertNotFound();
    });

    it('reaches every file the page links to', function (): void {
        $case = shareFilesCase();

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());
        $addresses = array_merge(
            shareAttributes($page, '//img[@src]', 'src'),
            shareAttributes($page, '//a[contains(@href, "/attachments/")]', 'href'),
        );

        // The thumbnail and the link of the photo, the link of the PDF.
        expect($addresses)->toHaveCount(3);

        foreach ($addresses as $address) {
            $this->get($address)->assertOk();
        }

        expect(shareAttributes($page, '//a[contains(@href, "/attachments/")]', 'target'))->toBe(['_blank', '_blank'])
            ->and(shareAttributes($page, '//a[contains(@href, "/attachments/")]', 'rel'))->toBe(['noopener noreferrer', 'noopener noreferrer'])
            ->and(shareAttributes($page, '//img[@src]', 'alt'))->toBe(['front.png'])
            ->and(shareTexts($page, '//tbody/tr[3]/td[20]')[0])->toStartWith('invoice.pdf');
    });

    it('shows an icon for a photo with no thumbnail and a file that is not shown in the page is a plain download', function (): void {
        $case = shareFilesCase();
        $formulas = $case['items']['formulas'];
        $webp = attachmentTo($case['inventory'], $formulas, attachmentUploadedAs('anim.webp', attachmentAnimatedWebpBytes(), 'image/webp'), null, $case['fields']['photos']);
        $zip = attachmentTo($case['inventory'], $formulas, attachmentUploadedAs('data.zip', "PK\x03\x04archive", 'application/zip'), null, $case['fields']['docs']);

        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect($webp->hasThumbnail())->toBeFalse()
            // The photo with no thumbnail: an icon named after the file, in a link.
            ->and(shareAttributes($page, '//tbody/tr[2]/td[19]//span[@role="img"]', 'aria-label'))->toBe(['anim.webp'])
            ->and($page->query('//tbody/tr[2]/td[19]//img')->length)->toBe(0)
            // The zip is no page: its link downloads (no new tab) and gives no inline address.
            ->and(shareAttributes($page, '//tbody/tr[2]/td[20]//a', 'target'))->toBe([''])
            ->and(shareAttributes($page, '//tbody/tr[2]/td[20]//a', 'href'))->toBe([shareFileUri($case['token'], $formulas, $zip)]);
    });

    it('does not note the use of the link', function (): void {
        $case = shareFilesCase();

        $this->get(shareFileUri($case['token'], $case['drill'], $case['pdf']))->assertOk();
        $this->get(shareFileUri($case['token'], $case['drill'], $case['photo'], true))->assertOk();

        expect($case['link']->refresh()->last_used_at)->toBeNull();
    });

    it('finds the item and the file whatever the case of their identifiers', function (): void {
        $case = shareFilesCase();

        $this->get('/s/'.$case['token'].'/items/'.strtoupper($case['drill']->ulid).'/attachments/'.strtoupper($case['pdf']->ulid))->assertOk();
    });

    it('answers the 404 page, not the JSON of the API, for a file that is gone from its disk and for a thumbnail that is', function (): void {
        $case = shareFilesCase();
        $unknown = $this->get('/s/'.ShareLinks::newToken().'/items/'.$case['drill']->ulid.'/attachments/'.$case['pdf']->ulid);
        Storage::disk(ATTACHMENT_TEST_DISK)->delete($case['pdf']->path);
        Storage::disk(ATTACHMENT_TEST_DISK)->delete($case['photo']->thumbnail_path);

        foreach ([
            shareFileUri($case['token'], $case['drill'], $case['pdf']),
            shareFileUri($case['token'], $case['drill'], $case['photo'], true),
        ] as $uri) {
            $response = $this->get($uri)->assertNotFound();

            expect($response->headers->get('Content-Type'))->toContain('text/html')
                ->and($response->getContent())->toBe($unknown->getContent())
                ->and($response->getContent())->not->toContain('file_not_found');
        }
    });

    it('answers the 404 page for the thumbnail of a photo that has none', function (): void {
        $case = shareFilesCase();
        $webp = attachmentTo($case['inventory'], $case['items']['formulas'], attachmentUploadedAs('anim.webp', attachmentAnimatedWebpBytes(), 'image/webp'), null, $case['fields']['photos']);
        $unknown = $this->get('/s/'.ShareLinks::newToken().'/items/'.$case['drill']->ulid.'/attachments/'.$case['pdf']->ulid);

        $response = $this->get(shareFileUri($case['token'], $case['items']['formulas'], $webp, true))->assertNotFound();

        expect($webp->hasThumbnail())->toBeFalse()
            ->and($response->getContent())->toBe($unknown->getContent())
            ->and($response->getContent())->not->toContain('thumbnail_not_found');
    });
});

describe('a link that does not show the files', function (): void {
    it('serves none: the 404 page for the file and for the thumbnail', function (): void {
        $case = shareFilesCase(files: false);
        $unknown = $this->get('/s/'.ShareLinks::newToken().'/items/'.$case['drill']->ulid.'/attachments/'.$case['pdf']->ulid);

        foreach ([false, true] as $thumbnail) {
            $response = $this->get(shareFileUri($case['token'], $case['drill'], $case['photo'], $thumbnail))->assertNotFound();

            // The same page as for a link that does not exist.
            expect($response->getContent())->toBe($unknown->getContent());
        }

        $this->get(shareFileUri($case['token'], $case['drill'], $case['pdf']))->assertNotFound();
    });

    it('leaves the columns of photos and files out of the page, and every address of a file', function (): void {
        $case = shareFilesCase(files: false);

        $response = sharePageGet($this, $case['token'])->assertOk();
        $page = shareDom($response);

        expect(shareTexts($page, '//thead//th'))->not->toContain('Photos')->not->toContain('Docs')->toHaveCount(18)
            ->and($response->getContent())->not->toContain('/attachments/')
            ->and($response->getContent())->not->toContain('invoice.pdf')
            ->and($response->getContent())->not->toContain('front.png')
            ->and($page->query('//img')->length)->toBe(0);
    });

    it('reads no file of the page of items', function (): void {
        $case = shareFilesCase(files: false);

        DB::enableQueryLog();
        sharePageGet($this, $case['token'])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'attachments'))->all())->toBe([]);
    });
});

describe('the answers that are the 404 page', function (): void {
    it('is the same whatever is wrong with the link: unknown, malformed, expired, revoked, sharing turned off', function (): void {
        $case = shareFilesCase();
        $expired = shareLinkMake($case['inventory'], $case['team']['owner']);
        $expired['link']->forceFill(['expires_at' => now()->subSecond()])->save();
        $revoked = shareLinkMake($case['inventory'], $case['team']['owner']);
        app(ShareLinks::class)->revoke($case['inventory'], $revoked['link']);

        $tokens = ['unknown' => ShareLinks::newToken(), 'malformed' => 'x', 'expired' => $expired['token'], 'revoked' => $revoked['token']];
        $answers = [];

        foreach ([false, true] as $thumbnail) {
            foreach ($tokens as $reason => $token) {
                $response = $this->get(shareFileUri($token, $case['drill'], $case['photo'], $thumbnail));
                $answers[$reason.($thumbnail ? ' thumbnail' : '')] = [$response->getStatusCode(), $response->getContent(), $response->headers->get('Content-Type')];
            }
        }

        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);
        $response = $this->get(shareFileUri($case['token'], $case['drill'], $case['photo'], true));
        $answers['disabled'] = [$response->getStatusCode(), $response->getContent(), $response->headers->get('Content-Type')];

        expect(collect($answers)->map(fn (array $answer): int => $answer[0])->unique()->values()->all())->toBe([404])
            ->and(collect($answers)->map(fn (array $answer): string => $answer[1])->unique())->toHaveCount(1)
            ->and(collect($answers)->map(fn (array $answer): ?string => $answer[2])->unique())->toHaveCount(1)
            // A page, not the JSON of the API.
            ->and($answers['unknown'][2])->toContain('text/html');
    });

    it('is the answer for a file that is not the one of that item of that inventory', function (): void {
        $case = shareFilesCase();
        $formulas = $case['items']['formulas'];
        $own = attachmentTo($case['inventory'], $formulas, attachmentPdf('other.pdf'), null, $case['fields']['docs']);

        // The workspace has another inventory, with an item and a file of its own, and the link is not for it.
        $other = inventoryIn($case['team']['workspace'], 'Secret plans');
        $secretField = fieldIn($other, 'file', 'Plans');
        $secretItem = itemIn($other);
        $secret = attachmentTo($other, $secretItem, attachmentPdf('plans.pdf'), null, $secretField);

        $wrong = [
            'the file of another item' => shareFileUri($case['token'], $formulas, $case['pdf']),
            'the file of an item of another inventory' => shareFileUri($case['token'], $secretItem, $secret),
            'an item of another inventory with a file of this one' => shareFileUri($case['token'], $secretItem, $case['pdf']),
            'a file of another inventory under an item of this one' => shareFileUri($case['token'], $case['drill'], $secret),
            'an item that does not exist' => '/s/'.$case['token'].'/items/01h00000000000000000000000/attachments/'.$case['pdf']->ulid,
            'a file that does not exist' => '/s/'.$case['token'].'/items/'.$case['drill']->ulid.'/attachments/01h00000000000000000000000',
            'identifiers that are no identifiers' => '/s/'.$case['token'].'/items/x/attachments/y',
        ];

        foreach ($wrong as $reason => $uri) {
            foreach ([$uri, $uri.'/thumbnail'] as $address) {
                $this->get($address)->assertNotFound();
            }
        }

        // Its own file is served.
        $this->get(shareFileUri($case['token'], $formulas, $own))->assertOk();
    });

    it('is the answer for a file the page does not show: one attached to an item with no field to sit in', function (): void {
        $case = shareFilesCase();
        $plain = attachmentTo($case['inventory'], $case['drill'], attachmentPdf('private-note.pdf'));

        $this->get(shareFileUri($case['token'], $case['drill'], $plain))->assertNotFound();
        $this->get(shareFileUri($case['token'], $case['drill'], $plain, true))->assertNotFound();

        $content = sharePageGet($this, $case['token'])->assertOk()->getContent();

        expect($content)->not->toContain('private-note.pdf')->not->toContain($plain->ulid);
    });

    it('is the answer for a file whose field holds no files any more', function (): void {
        $case = shareFilesCase();
        $docs = $case['fields']['docs'];

        DB::table('inventory_fields')->where('id', $docs->id)->update(['type' => 'text']);

        // The file is still a row of the item, but the field it sat in holds text now (the application never does it, a restore of old data
        // could): the page does not show it, and does not serve it.
        $this->get(shareFileUri($case['token'], $case['drill'], $case['pdf']))->assertNotFound();
    });
});

describe('no session', function (): void {
    it('is started by no file route: no cookie, no session', function (): void {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $case = shareFilesCase();

        foreach ([
            shareFileUri($case['token'], $case['drill'], $case['photo'], true),
            shareFileUri($case['token'], $case['drill'], $case['pdf']),
            shareFileUri('not-a-token', $case['drill'], $case['pdf']),
        ] as $uri) {
            $response = $this->get($uri);

            expect($response->headers->getCookies())->toBe([]);
        }

        expect(DB::table('sessions')->count())->toBe(0)
            ->and(app('session')->driver()->isStarted())->toBeFalse();
    });
});

describe('the pace of the files', function (): void {
    it('is limited for each network and link together, apart from the pace of the page', function (): void {
        config(['inventories.share_files_per_minute' => 2, 'inventories.share_page_per_minute' => 2]);
        $case = shareFilesCase();
        $thumbnail = shareFileUri($case['token'], $case['drill'], $case['photo'], true);

        $this->get($thumbnail)->assertOk();
        $this->get($thumbnail)->assertOk();
        $refused = $this->get($thumbnail)->assertStatus(429);

        expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0);

        // The page has a count of its own.
        sharePageGet($this, $case['token'])->assertOk();

        // And so does another link.
        ['token' => $other] = shareLinkMake($case['inventory'], $case['team']['owner']);
        $this->get(shareFileUri($other, $case['drill'], $case['photo'], true))->assertOk();
    });

    it('lets a page of items load all its thumbnails with the pace of the default', function (): void {
        $case = shareFilesCase();
        $thumbnail = shareFileUri($case['token'], $case['drill'], $case['photo'], true);

        // A page shows 50 items with a handful of photos each: the default pace is far above what one page asks for.
        foreach (range(1, 60) as $unused) {
            $this->get($thumbnail)->assertOk();
        }
    });
});

describe('the routes of the files', function (): void {
    it('only read: other methods are not allowed', function (): void {
        $case = shareFilesCase();

        $this->post(shareFileUri($case['token'], $case['drill'], $case['pdf']))->assertStatus(405);
        $this->delete(shareFileUri($case['token'], $case['drill'], $case['pdf']))->assertStatus(405);
    });
});
