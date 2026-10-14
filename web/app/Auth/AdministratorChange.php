<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * What {@see Accounts::administrator()} did.
 */
enum AdministratorChange: string
{
    /** There was no account for the address: one was created, as an administrator. */
    case Created = 'created';

    /** The account existed and now administers the instance. */
    case Promoted = 'promoted';

    /** The account already administered the instance: nothing changed. */
    case Unchanged = 'unchanged';
}
