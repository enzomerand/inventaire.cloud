<?php

declare(strict_types=1);

use App\Http\Middleware\SetPermissionsPolicy;
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

describe('the Permissions-Policy header', function (): void {
    it('goes out with every kind of answer', function (string $method, string $uri): void {
        $this->call($method, $uri, server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertHeader('Permissions-Policy', SetPermissionsPolicy::POLICY);
    })->with([
        'the home page' => ['GET', '/'],
        'the API, unauthenticated' => ['GET', '/api/v1/me'],
        'a page that does not exist' => ['GET', '/nothing-here'],
        'the health check' => ['GET', '/up'],
        'the service worker' => ['GET', '/sw.js'],
        'a share link that does not exist' => ['GET', '/s/'.str_repeat('a', 43)],
    ]);

    it('goes out while the instance is not installed yet', function (): void {
        $this->bootInstaller(installed: false);

        $this->get('/')->assertRedirect()->assertHeader('Permissions-Policy', SetPermissionsPolicy::POLICY);
    });

    it('keeps the camera for the application alone and turns off the features it never uses', function (): void {
        $this->get('/login')->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), '
            .'display-capture=(), payment=(), usb=(), serial=(), hid=(), bluetooth=(), midi=(), accelerometer=(), '
            .'gyroscope=(), magnetometer=()');
    });
});
