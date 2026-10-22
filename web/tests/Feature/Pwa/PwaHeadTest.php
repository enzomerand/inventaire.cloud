<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/sharing.php';

uses(RefreshDatabase::class);

/*
 * The head of the pages: the application links its manifest and its icons, and the page of a share link carries the icons and the color only
 * (its address is a secret: no manifest to install, no worker).
 */

describe('the pages of the application', function (): void {
    it('link the manifest, the color, the icons and the name of the installed app', function (): void {
        $response = $this->get('/login')->assertOk();
        $page = shareDom($response);

        expect(shareAttributes($page, '//head/link[@rel="manifest"]', 'href'))->toBe(['/manifest.webmanifest'])
            ->and(shareAttributes($page, '//head/meta[@name="theme-color"]', 'content'))->toBe(['#066fd1'])
            ->and(shareAttributes($page, '//head/link[@rel="icon"]', 'href'))->toBe(['/icons/icon.svg', '/favicon.ico'])
            ->and(shareAttributes($page, '//head/link[@rel="icon"]', 'type'))->toBe(['image/svg+xml', ''])
            ->and(shareAttributes($page, '//head/link[@rel="icon"]', 'sizes'))->toBe(['', '32x32'])
            ->and(shareAttributes($page, '//head/link[@rel="apple-touch-icon"]', 'href'))->toBe(['/icons/apple-touch-icon.png'])
            ->and(shareAttributes($page, '//head/meta[@name="apple-mobile-web-app-title"]', 'content'))->toBe([config('app.name')]);
    });

    it('link files that exist: the manifest answers and every icon is in public/', function (): void {
        $page = shareDom($this->get('/login')->assertOk());

        $this->get(shareAttributes($page, '//head/link[@rel="manifest"]', 'href')[0])->assertOk();

        foreach (shareAttributes($page, '//head/link[@rel="icon" or @rel="apple-touch-icon"]', 'href') as $href) {
            expect(is_file(public_path(ltrim($href, '/'))))->toBeTrue("{$href} is not in public/");
        }
    });
});

describe('the page of a share link', function (): void {
    it('carries the color and the icons, and neither a manifest nor a script', function (): void {
        $case = shareFixture();
        $page = shareDom(sharePageGet($this, $case['token'])->assertOk());

        expect(shareAttributes($page, '//head/meta[@name="theme-color"]', 'content'))->toBe(['#066fd1'])
            ->and(shareAttributes($page, '//head/link[@rel="icon"]', 'href'))->toBe(['/icons/icon.svg', '/favicon.ico'])
            ->and(shareAttributes($page, '//head/link[@rel="apple-touch-icon"]', 'href'))->toBe(['/icons/apple-touch-icon.png'])
            ->and($page->query('//link[@rel="manifest"]')->length)->toBe(0)
            ->and($page->query('//meta[@name="apple-mobile-web-app-title"]')->length)->toBe(0)
            ->and($page->query('//script')->length)->toBe(0);
    });
});

it('has a favicon that is not empty: a 32 px icon', function (): void {
    $icon = (string) file_get_contents(public_path('favicon.ico'));

    // The header of an icon file (reserved, type 1, one image), then the entry of that image (32 x 32) holding a PNG.
    expect(strlen($icon))->toBeGreaterThan(22)
        ->and(unpack('vreserved/vtype/vcount', $icon))->toBe(['reserved' => 0, 'type' => 1, 'count' => 1])
        ->and(ord($icon[6]))->toBe(32)
        ->and(ord($icon[7]))->toBe(32)
        ->and(substr($icon, 22, 8))->toBe("\x89PNG\r\n\x1a\n");
});
