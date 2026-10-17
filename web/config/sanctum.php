<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiSession;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Sanctum;

/*
 * Only what differs from the package's own configuration is set here: the rest (the guard) is merged from
 * vendor/laravel/sanctum/config/sanctum.php.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful domains
    |--------------------------------------------------------------------------
    |
    | Hosts whose API requests use the session cookie, like the web pages; every
    | other client is stateless. See the README, "Stateful requests (Sanctum)".
    |
    */

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | API tokens
    |--------------------------------------------------------------------------
    |
    | `token_prefix`: what every API token starts with (`SANCTUM_TOKEN_PREFIX`,
    | `inv_`). It makes a token recognisable at a glance, and by the secret
    | scanners of code hosts, if one is pushed by mistake.
    |
    */

    'token_prefix' => (string) env('SANCTUM_TOKEN_PREFIX', 'inv_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum middleware
    |--------------------------------------------------------------------------
    |
    | The package's defaults, but for `authenticate_session`: the application's
    | own, which also refuses a signed-in session that has no fingerprint (see
    | App\Auth\SessionFingerprint). The array is merged as a whole, so the other
    | two entries are repeated here as the package has them.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateApiSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
