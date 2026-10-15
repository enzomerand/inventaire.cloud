<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Limits on creating workspaces
    |--------------------------------------------------------------------------
    |
    | Anybody who is signed in can create workspaces, so both the number a person
    | can belong to and the pace at which they create them are capped: a script
    | in a loop must not fill the database. `max_per_user` counts the workspaces
    | an account belongs to, whoever created them (the API answers 409 with
    | `"code": "workspace_limit_reached"` beyond it); `create_per_hour` is the
    | number of creations a person can make per hour (429).
    */
    'max_per_user' => (int) env('WORKSPACES_MAX_PER_USER', 50),

    'create_per_hour' => (int) env('WORKSPACES_CREATE_PER_HOUR', 20),

];
