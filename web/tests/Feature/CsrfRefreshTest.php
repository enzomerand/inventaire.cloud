<?php

declare(strict_types=1);

it('answers 204 and is never cached', function (): void {
    $this->get(route('csrf.refresh'))
        ->assertNoContent()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('hands out the token in the readable XSRF-TOKEN cookie', function (): void {
    $response = $this->get(route('csrf.refresh'));

    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeFalse();
});

it('advertises the refresh endpoint to the scripts and no login URL yet', function (): void {
    $this->get(route('home'))
        ->assertSee('<meta name="csrf-refresh-url" content="/csrf-token">', false)
        ->assertDontSee('name="login-url"', false);
});
