<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
 * Only what differs from the package's own configuration is set here: the rest (guard, token expiry and prefix,
 * middleware) is merged from vendor/laravel/sanctum/config/sanctum.php.
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

];
