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
    | replaces the previous one. Sending is limited per address and network
    | together (somebody asking again and again), per address alone (so nobody's
    | inbox can be flooded from many networks) and per network alone (so the
    | instance cannot be used to mail arbitrary people); an IPv6 client counts
    | as its whole /64. Typing codes back is limited per network and address, on
    | top of the attempts allowed for each code, and wrong codes are also
    | counted per address over a longer, fixed period that opens with the first
    | of them, whatever the codes they were typed against: asking for a new
    | code does not give guesses back.
    |
    */

    'login_codes' => [
        'lifetime_minutes' => 10,
        'max_attempts' => 5,
        'send_per_email_and_ip' => ['attempts' => 3, 'minutes' => 10],
        'send_per_email' => ['attempts' => 10, 'minutes' => 60],
        'send_per_ip' => ['attempts' => 20, 'minutes' => 10],
        'verify_per_minute' => 10,
        'failures_per_email' => ['attempts' => 10, 'minutes' => 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account deletion and export
    |--------------------------------------------------------------------------
    |
    | Deleting an account is confirmed with a 6-digit code sent to its address:
    | a code of its own, valid for a few minutes and a few wrong guesses, that
    | has nothing to do with the sign-in codes (see App\Auth\AccountDeletionCodes).
    | Sending is limited per user over a short and a longer period, typing codes
    | back per user and minute, and the export of the account's data per user.
    */
    'account_deletion' => [
        'lifetime_minutes' => 10,
        'max_attempts' => 5,
        'send_per_user' => ['attempts' => 3, 'minutes' => 10],
        'send_per_user_hourly' => ['attempts' => 6, 'minutes' => 60],
        'verify_per_minute' => 5,
    ],

    'account_export' => ['attempts' => 5, 'minutes' => 10],

];
