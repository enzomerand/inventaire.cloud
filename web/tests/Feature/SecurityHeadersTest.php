<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('the Referrer-Policy header', function (): void {
    it('goes out with the pages and the API alike', function (string $method, string $uri): void {
        $this->call($method, $uri, server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost/login', 'HTTP_ORIGIN' => 'http://localhost'])
            ->assertHeader('Referrer-Policy', 'same-origin');
    })->with([
        'the home page' => ['GET', '/'],
        'the login page' => ['GET', '/login'],
        'the API, unauthenticated' => ['GET', '/api/v1/me'],
        'an API call that is refused' => ['POST', '/api/v1/auth/code'],
        'a page that does not exist' => ['GET', '/nothing-here'],
        'the health check' => ['GET', '/up'],
    ]);

    it('goes out while the instance is not installed yet', function (): void {
        $this->bootInstaller(installed: false);

        $this->get('/')->assertRedirect()->assertHeader('Referrer-Policy', 'same-origin');
    });
});
