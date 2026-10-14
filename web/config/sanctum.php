<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful domains
    |--------------------------------------------------------------------------
    |
    | Requests to the API that come from one of these hosts (judged by their
    | Referer or Origin header) are authenticated with the session cookie, like
    | the web pages: this is how the web front signs people in. Every other
    | client is stateless and will use API tokens.
    |
    | The default covers the usual development addresses and the host (with its
    | port) of APP_URL, so a production instance works out of the box when it is
    | reached on its public address. Reach it on another host (a second domain, a
    | LAN address, a different local port) and sign-in silently does nothing:
    | list every such host in SANCTUM_STATEFUL_DOMAINS, comma-separated, with its
    | port when it is not the default one:
    |
    |     SANCTUM_STATEFUL_DOMAINS=inventory.example.com,inventory.example.net:8443
    |
    | The variable replaces this default, so repeat the APP_URL host in it.
    |
    */

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Authentication guards
    |--------------------------------------------------------------------------
    |
    | The guard(s) checked first for a signed-in user: the session guard the web
    | pages use, so the same login serves the pages and the API.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Token expiration
    |--------------------------------------------------------------------------
    |
    | Minutes before an API token expires; null means never (tokens can still
    | carry their own expiry date).
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token prefix
    |--------------------------------------------------------------------------
    |
    | Prepended to generated API tokens so secret scanners can recognise them.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum middleware
    |--------------------------------------------------------------------------
    |
    | The middleware Sanctum runs on stateful requests.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
