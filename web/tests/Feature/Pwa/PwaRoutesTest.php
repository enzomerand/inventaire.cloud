<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * What the three files of the installable app (the manifest, the worker, the offline page) have in common: browsers fetch them on their own,
 * so none of them has a session, a cookie or a CSRF token to give; and the wizard is a page of the application too, so they work before the
 * installation.
 */

dataset('pwa routes', [
    'the manifest' => ['pwa.manifest', '/manifest.webmanifest'],
    'the worker' => ['pwa.worker', '/sw.js'],
    'the offline page' => ['pwa.offline', '/offline'],
]);

it('has a name and a path', function (string $name, string $path): void {
    expect(route($name, absolute: false))->toBe($path);
})->with('pwa routes');

it('runs without a session, without a CSRF check and without anything that sets a cookie', function (string $name): void {
    $route = app(Router::class)->getRoutes()->getByName($name);
    $middleware = app(Router::class)->gatherRouteMiddleware($route);

    expect($middleware)->not->toContain(StartSession::class)
        ->not->toContain(ShareErrorsFromSession::class)
        ->not->toContain(PreventRequestForgery::class);
})->with('pwa routes');

it('sends no cookie and writes no session', function (string $name, string $path): void {
    // Sessions in files of a folder of their own, to see whether a request writes one.
    $folder = sys_get_temp_dir().'/pwa-sessions-'.bin2hex(random_bytes(4));
    mkdir($folder);
    config(['session.driver' => 'file', 'session.files' => $folder]);
    app('session')->forgetDrivers();

    try {
        $response = $this->get($path);

        $response->assertOk();

        expect($response->headers->getCookies())->toBe([])
            ->and($response->headers->has('Set-Cookie'))->toBeFalse()
            ->and(glob($folder.'/*'))->toBe([]);

        // The harness: a page that has a session writes a file and sends the cookie.
        $login = $this->get('/login');

        expect($login->headers->getCookies())->not->toBe([])
            ->and(glob($folder.'/*'))->toHaveCount(1);
    } finally {
        array_map('unlink', glob($folder.'/*') ?: []);
        rmdir($folder);
    }
})->with('pwa routes');

it('is not indexed, and says so in the header', function (string $name, string $path): void {
    $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
})->with('pwa routes');

it('only answers GET', function (string $name, string $path): void {
    $this->post($path)->assertStatus(405);
})->with('pwa routes');

describe('on a fresh instance', function (): void {
    beforeEach(function (): void {
        $this->bootInstaller(installed: false);
    });

    it('is served, not sent to the wizard', function (string $name, string $path): void {
        $response = $this->get($path);

        $response->assertOk();

        expect($response->headers->has('Location'))->toBeFalse();
    })->with('pwa routes');

    it('still sends every other page to the wizard', function (): void {
        $this->get('/some/page')->assertRedirect(route('install.show'));
    });

    it('serves the worker with its version and the manifest with its icons', function (): void {
        $worker = $this->get('/sw.js')->assertOk();
        $manifest = json_decode($this->get('/manifest.webmanifest')->assertOk()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        expect($worker->getContent())->toStartWith('self.INVENTAIRE_SW = {"version":"')
            ->and($manifest['icons'])->toHaveCount(3);
    });
});
