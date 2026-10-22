<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

/*
 * The web app manifest (`/manifest.webmanifest`): what makes the application installable. Its shape, its files, and its language.
 */

/**
 * The manifest, asked for the way a browser does, in a language (the empty header is the default language of the instance: the test
 * client sends `en-us` unless told otherwise).
 *
 * @return array{0: TestResponse, 1: array<string, mixed>}
 */
function pwaManifest(object $test, string $acceptLanguage = ''): array
{
    $response = $test->get('/manifest.webmanifest', ['Accept-Language' => $acceptLanguage]);

    return [$response, json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
}

it('is JSON with the media type of manifests, revalidated each time', function (): void {
    [$response] = pwaManifest($this);

    $response->assertOk()->assertHeader('Content-Type', 'application/manifest+json; charset=utf-8');

    expect($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('max-age'))->toBeFalse()
        ->and($response->headers->hasCacheControlDirective('public'))->toBeFalse()
        // The text and the language follow the request.
        ->and(array_map('strtolower', $response->getVary()))->toContain('accept-language')->toContain('cookie');
});

it('describes the application that starts at /app and owns the whole site', function (): void {
    [, $manifest] = pwaManifest($this);

    expect($manifest)->toMatchArray([
        'id' => '/app',
        'name' => config('app.name'),
        'short_name' => config('app.name'),
        'dir' => 'ltr',
        'start_url' => '/app',
        'scope' => '/',
        'display' => 'standalone',
        'theme_color' => '#066fd1',
        'background_color' => '#fafafa',
    ])->and($manifest['description'])->toBeString()->not->toBe('');
});

it('names the instance by its configured name', function (): void {
    config(['app.name' => 'Acme Stock']);

    [, $manifest] = pwaManifest($this);

    expect($manifest['name'])->toBe('Acme Stock')->and($manifest['short_name'])->toBe('Acme Stock');
});

it('has no address with a host: the paths work behind any proxy', function (): void {
    [, $manifest] = pwaManifest($this);

    $paths = [$manifest['id'], $manifest['start_url'], $manifest['scope'], ...array_column($manifest['icons'], 'src')];

    foreach ($paths as $path) {
        expect($path)->toStartWith('/')->not->toStartWith('//')->not->toContain('://');
    }
});

it('lists an icon of 192 px and one of 512 px, and a maskable one, that all exist', function (): void {
    [, $manifest] = pwaManifest($this);

    expect($manifest['icons'])->toHaveCount(3);

    foreach ($manifest['icons'] as $icon) {
        $file = public_path(ltrim($icon['src'], '/'));

        expect(is_file($file))->toBeTrue("{$icon['src']} is not in public/");

        [$width, $height] = getimagesize($file);

        expect($icon['type'])->toBe('image/png')
            ->and($icon['sizes'])->toBe("{$width}x{$height}")
            ->and($icon['purpose'])->toBeIn(['any', 'maskable']);
    }

    expect(array_column($manifest['icons'], 'sizes'))->toBe(['192x192', '512x512', '512x512'])
        ->and(array_column($manifest['icons'], 'purpose'))->toBe(['any', 'any', 'maskable']);
});

it('is in French by default and in English for a browser that asks for it', function (): void {
    [, $french] = pwaManifest($this);
    [, $english] = pwaManifest($this, 'en');

    expect($french['lang'])->toBe('fr')
        ->and($french['description'])->toBe("Outil d'inventaire gratuit pour les professionnels et les particuliers.")
        ->and($english['lang'])->toBe('en')
        ->and($english['description'])->toBe('Free inventory tool for professionals and individuals.');
});
