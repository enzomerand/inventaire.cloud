<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

/*
 * The page the service worker shows when the network is down (`/offline`): the same for everybody, self-contained, and not for search engines.
 */

/**
 * The page of a response, ready to be queried with XPath.
 */
function pwaDom(TestResponse $response): DOMXPath
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    libxml_clear_errors();

    return new DOMXPath($dom);
}

/**
 * The values of an attribute of the nodes an XPath query finds, or the text of the nodes when no attribute is named.
 *
 * @return list<string>
 */
function pwaValues(DOMXPath $page, string $query, ?string $attribute = null): array
{
    $values = [];

    foreach ($page->query($query) ?: [] as $node) {
        $values[] = $attribute === null ? trim($node->textContent) : ($node instanceof DOMElement ? $node->getAttribute($attribute) : '');
    }

    return $values;
}

it('is a page of the language of the instance, with a title, a sentence and a link to try again', function (): void {
    $response = $this->get('/offline', ['Accept-Language' => ''])->assertOk();
    $page = pwaDom($response);

    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and(pwaValues($page, '/html', 'lang'))->toBe(['fr'])
        ->and(pwaValues($page, '//title'))->toBe(['Vous êtes hors ligne · '.config('app.name')])
        ->and(pwaValues($page, '//h1'))->toBe(['Vous êtes hors ligne'])
        ->and(pwaValues($page, '//main/p'))->toBe(['Vérifiez votre connexion, puis réessayez.'])
        ->and(pwaValues($page, '//main/a'))->toBe(['Réessayer'])
        // The empty address is the one of the page asked for: the link loads it again, without a script.
        ->and(pwaValues($page, '//main/a', 'href'))->toBe([''])
        ->and(pwaValues($page, '//meta[@name="viewport"]', 'content'))->toBe(['width=device-width, initial-scale=1, viewport-fit=cover'])
        ->and(pwaValues($page, '//meta[@name="theme-color"]', 'content'))->toBe(['#066fd1']);
});

it('speaks English to a browser that asks for it', function (): void {
    $page = pwaDom($this->get('/offline', ['Accept-Language' => 'en'])->assertOk());

    expect(pwaValues($page, '/html', 'lang'))->toBe(['en'])
        ->and(pwaValues($page, '//h1'))->toBe(['You are offline'])
        ->and(pwaValues($page, '//main/p'))->toBe(['Check your connection, then try again.'])
        ->and(pwaValues($page, '//main/a'))->toBe(['Try again']);
});

it('is not for search engines, by its tag and by its header', function (): void {
    $response = $this->get('/offline')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');

    expect(pwaValues(pwaDom($response), '//meta[@name="robots"]', 'content'))->toBe(['noindex']);
});

it('carries nothing of the visitor and nothing a session needs: no CSRF token, no cookie', function (): void {
    $response = $this->get('/offline')->assertOk();
    $page = pwaDom($response);

    expect($page->query('//meta[@name="csrf-token"]')->length)->toBe(0)
        ->and($page->query('//meta[@name="login-url"]')->length)->toBe(0)
        ->and($response->getContent())->not->toContain('csrf')->not->toContain('_token')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('is self-contained: inline style and icon, no script, no stylesheet, no image and no address of anywhere else', function (): void {
    $response = $this->get('/offline')->assertOk();
    $page = pwaDom($response);

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

it('only answers GET', function (): void {
    $this->post('/offline')->assertStatus(405);
});
