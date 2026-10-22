<?php

declare(strict_types=1);

use App\Pwa\ServiceWorkerScript;
use Illuminate\Testing\TestResponse;

/*
 * The service worker (`/sw.js`): how it is served, what the server writes in front of it, and the version that makes browsers replace it.
 * What the worker does once it runs is tested in tests/js/sw.
 */

/**
 * The configuration line the server writes in front of the worker, decoded.
 *
 * @return array{version: string, offlineUrl: string}
 */
function pwaWorkerConfiguration(TestResponse $response): array
{
    $first = strtok($response->getContent(), "\n");

    expect($first)->toStartWith('self.INVENTAIRE_SW = ')->toEndWith(';');

    return json_decode(substr($first, strlen('self.INVENTAIRE_SW = '), -1), true, flags: JSON_THROW_ON_ERROR);
}

it('is a script served for the whole site and revalidated each time', function (): void {
    $response = $this->get('/sw.js');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('Service-Worker-Allowed', '/');

    expect($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('max-age'))->toBeFalse()
        ->and($response->headers->hasCacheControlDirective('public'))->toBeFalse();
});

it('starts with its configuration, then is the source of the worker, untouched', function (): void {
    $response = $this->get('/sw.js');
    $configuration = pwaWorkerConfiguration($response);
    $source = (string) file_get_contents(resource_path('js/sw/service-worker.js'));

    expect($configuration)->toHaveKeys(['version', 'offlineUrl'])
        ->and($configuration['version'])->toMatch('/^[0-9a-f]{16}$/')
        ->and($configuration['offlineUrl'])->toBe('/offline')
        ->and($response->getContent())->toBe('self.INVENTAIRE_SW = '.json_encode($configuration, JSON_UNESCAPED_SLASHES).";\n".$source)
        ->and($source)->not->toBe('');
});

it('points at a page the application serves', function (): void {
    $configuration = pwaWorkerConfiguration($this->get('/sw.js'));

    $this->get($configuration['offlineUrl'])->assertOk();
});

it('has a version that does not change while nothing it is made of changes', function (): void {
    $first = pwaWorkerConfiguration($this->get('/sw.js'))['version'];
    $second = pwaWorkerConfiguration($this->get('/sw.js'))['version'];

    expect($second)->toBe($first)->and(app(ServiceWorkerScript::class)->version())->toBe($first);
});

it('has another version when the name of the instance changes, since the offline page it keeps shows it', function (): void {
    config(['app.name' => 'First name']);
    $first = pwaWorkerConfiguration($this->get('/sw.js'))['version'];

    config(['app.name' => 'Second name']);
    $second = pwaWorkerConfiguration($this->get('/sw.js'))['version'];

    expect($second)->not->toBe($first)->and($second)->toMatch('/^[0-9a-f]{16}$/');
});

it('has another version when a translation file changes, since the offline page it keeps is written with those sentences', function (): void {
    $directory = sys_get_temp_dir().'/inventaire-lang-'.bin2hex(random_bytes(4));
    $original = app()->langPath();

    mkdir($directory);
    copy(lang_path('fr.json'), $directory.'/fr.json');
    app()->useLangPath($directory);

    try {
        $script = app(ServiceWorkerScript::class);
        $first = $script->version();

        expect($script->version())->toBe($first);

        // A sentence of the offline page is worded otherwise.
        file_put_contents($directory.'/fr.json', str_replace('Connexion impossible', 'Pas de connexion', (string) file_get_contents($directory.'/fr.json')));
        $second = $script->version();

        // A language is added.
        file_put_contents($directory.'/de.json', '{}');
        $third = $script->version();

        expect($second)->not->toBe($first)
            ->and($third)->not->toBe($second)
            ->and($third)->not->toBe($first)
            ->and([$first, $second, $third])->each->toMatch('/^[0-9a-f]{16}$/');
    } finally {
        app()->useLangPath($original);

        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
});

it('is the same in every language: the version does not depend on who asks', function (): void {
    $french = $this->get('/sw.js', ['Accept-Language' => '']);
    $english = $this->get('/sw.js', ['Accept-Language' => 'en']);

    expect($english->getContent())->toBe($french->getContent());
});
