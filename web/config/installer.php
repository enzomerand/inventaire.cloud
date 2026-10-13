<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Installer
|--------------------------------------------------------------------------
|
| Settings of the install wizard (web and CLI). See App\Installer.
|
*/

return [

    /*
    | Directory holding the installer's own files: the "installed" marker, the
    | wizard progress, the one-time setup token and the locks. It is outside the
    | public and local disks (which hold user files), outside the web root, and
    | readable by the group so the web server and the person running the console
    | installer can both use it.
    */
    'state_path' => storage_path('installer'),

    /*
    | Oldest server version accepted for each database engine. Lower versions
    | lack features the schema relies on (JSON columns, modern defaults).
    */
    'minimum_versions' => [
        'pgsql' => '14',
        'mysql' => '8.0',
        'mariadb' => '10.11',
    ],

    /*
    | Email verification of the mail step: a 6-digit code is sent to the test
    | recipient and must be entered within the lifetime, in a limited number of
    | attempts.
    */
    'mail_code' => [
        'lifetime_minutes' => 15,
        'max_attempts' => 5,
    ],

    /*
    | Wrong or right, the setup token form accepts this many attempts per
    | minute and per IP address.
    */
    'token_attempts_per_minute' => 5,

];
