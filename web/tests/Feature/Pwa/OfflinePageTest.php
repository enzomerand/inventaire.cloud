<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/sharing.php';

/*
 * The page the service worker shows when the network is down (`/offline`): the same for everybody, self-contained, and not for search engines.
 */

it('is a page of the language of the instance, with a title, a sentence that names the instance and a link to try again', function (): void {
    $response = $this->get('/offline', ['Accept-Language' => ''])->assertOk();
    $page = shareDom($response);

    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and(shareAttributes($page, '/html', 'lang'))->toBe(['fr'])
        ->and(shareTexts($page, '//title'))->toBe(['Connexion impossible · '.config('app.name')])
        ->and(shareTexts($page, '//h1'))->toBe(['Connexion impossible'])
        // A request that fails can be a network that is down or a server that is: the page does not say which.
        ->and(shareTexts($page, '//main/p'))->toBe(['Impossible de joindre '.config('app.name').'. Vérifiez votre connexion, puis réessayez.'])
        ->and(shareTexts($page, '//main/a'))->toBe(['Réessayer'])
        // The empty address is the one of the page asked for: the link loads it again, without a script.
        ->and(shareAttributes($page, '//main/a', 'href'))->toBe([''])
        ->and(shareAttributes($page, '//meta[@name="viewport"]', 'content'))->toBe(['width=device-width, initial-scale=1, viewport-fit=cover'])
        ->and(shareAttributes($page, '//meta[@name="theme-color"]', 'content'))->toBe(['#066fd1']);
});

it('speaks English to a browser that asks for it', function (): void {
    $page = shareDom($this->get('/offline', ['Accept-Language' => 'en'])->assertOk());

    expect(shareAttributes($page, '/html', 'lang'))->toBe(['en'])
        ->and(shareTexts($page, '//h1'))->toBe(['Connection unavailable'])
        ->and(shareTexts($page, '//main/p'))->toBe(['We could not reach '.config('app.name').'. Check your connection, then try again.'])
        ->and(shareTexts($page, '//main/a'))->toBe(['Try again']);
});

it('names the instance in its sentence, and escapes the name', function (): void {
    config(['app.name' => 'Stock <b>&</b> Co']);

    $response = $this->get('/offline', ['Accept-Language' => 'en'])->assertOk();
    $page = shareDom($response);

    expect(shareTexts($page, '//main/p'))->toBe(['We could not reach Stock <b>&</b> Co. Check your connection, then try again.'])
        ->and($page->query('//main/p/b')->length)->toBe(0)
        ->and($response->getContent())->not->toContain('<b>&</b>');
});

it('does not say that the person is offline: the server may be the one that is down', function (): void {
    foreach (['', 'en'] as $language) {
        $content = mb_strtolower($this->get('/offline', ['Accept-Language' => $language])->assertOk()->getContent());

        expect($content)->not->toContain('hors ligne')->not->toContain('offline')->not->toContain('you are off');
    }
});

it('is not for search engines, by its tag (its header is checked with the other pages of the app)', function (): void {
    $response = $this->get('/offline')->assertOk();

    expect(shareAttributes(shareDom($response), '//meta[@name="robots"]', 'content'))->toBe(['noindex']);
});

it('carries nothing a session needs: no CSRF token (its cookies are checked with the other pages of the app)', function (): void {
    $response = $this->get('/offline')->assertOk();
    $page = shareDom($response);

    expect($page->query('//meta[@name="csrf-token"]')->length)->toBe(0)
        ->and($page->query('//meta[@name="login-url"]')->length)->toBe(0)
        ->and($response->getContent())->not->toContain('csrf')->not->toContain('_token');
});

it('is self-contained: inline style and icon, no script, no stylesheet, no image and no address of anywhere else', function (): void {
    $response = $this->get('/offline')->assertOk();
    $page = shareDom($response);

    expect($page->query('//script')->length)->toBe(0)
        ->and($page->query('//link')->length)->toBe(0)
        ->and($page->query('//img')->length)->toBe(0)
        ->and($page->query('//style')->length)->toBe(1)
        ->and($page->query('//main/svg/rect')->length)->toBeGreaterThan(0)
        ->and($response->getContent())->not->toMatch('#https?://#i')->not->toContain('/build/');
});

it('is the same page for everybody', function (): void {
    $first = $this->get('/offline', ['Accept-Language' => ''])->getContent();
    $second = $this->withCookie('anything', 'x')->get('/offline', ['Accept-Language' => ''])->getContent();

    expect($second)->toBe($first);
});
