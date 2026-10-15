<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Limit on creating accounts from the administration
    |--------------------------------------------------------------------------
    |
    | Creating an account sends a mail to the address it is for, so an
    | administrator whose session is stolen (or who runs a script in a loop)
    | must not be able to use the instance to mail the world: this is the
    | number of accounts one administrator can create per hour (429 beyond it).
    */
    'user_creation_per_hour' => (int) env('ADMIN_USER_CREATION_PER_HOUR', 60),

];
