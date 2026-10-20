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

    /*
    |--------------------------------------------------------------------------
    | Members and invitations
    |--------------------------------------------------------------------------
    |
    | `max_members` caps the members of a workspace together with the invitations
    | that are waiting for an answer (the API answers 409 with
    | `"code": "member_limit_reached"` beyond it). `max_memberships_per_user` caps
    | the workspaces an account can join by accepting invitations (409,
    | `"code": "membership_limit_reached"`). An invitation can be accepted for
    | `invitation_ttl_days` days; the owner can send it again (the mail), but not
    | more than once every ten minutes. `invitations_per_hour` is the number of
    | mails a person can have the application send as an owner, first sends and
    | resends together (429): an invitation mails an address the owner chooses.
    */
    'max_members' => (int) env('WORKSPACES_MAX_MEMBERS', 50),

    'max_memberships_per_user' => (int) env('WORKSPACES_MAX_MEMBERSHIPS_PER_USER', 100),

    'invitation_ttl_days' => (int) env('WORKSPACES_INVITATION_TTL_DAYS', 14),

    'invitations_per_hour' => (int) env('WORKSPACES_INVITATIONS_PER_HOUR', 20),

    'invitation_resend_minutes' => 10,

];
