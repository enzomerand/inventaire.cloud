<?php

declare(strict_types=1);

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" for your
    | application. Accounts have no password: people sign in with a code sent
    | by email, so there is no password reset "broker" either.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
            // Minutes the "remember me" cookie lasts: people stay signed in for 30 days. It is the cookie that
            // lives that long, not the session (anonymous visitors have sessions too, see SESSION_LIFETIME).
            'remember' => 60 * 24 * 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-in codes
    |--------------------------------------------------------------------------
    |
    | People sign in with a 6-digit code sent to their email address. A code is
    | valid for a few minutes and for a few wrong guesses; asking for a new one
    | replaces the previous one. Sending is limited per address (so nobody's
    | inbox can be flooded) and per IP address (so the instance cannot be used
    | to mail arbitrary people); typing codes back is limited per IP address and
    | address, on top of the attempts allowed for each code.
    |
    */

    'login_codes' => [
        'lifetime_minutes' => 10,
        'max_attempts' => 5,
        'send_per_email' => ['attempts' => 3, 'minutes' => 10],
        'send_per_ip' => ['attempts' => 20, 'minutes' => 10],
        'verify_per_minute' => 10,
    ],

];
