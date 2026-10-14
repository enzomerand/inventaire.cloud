<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Result of checking a sign-in code.
 *
 * A missing code (never requested, replaced, already used or burnt) is
 * reported as {@see self::Expired}: from the outside they all mean "ask for a
 * new one".
 */
enum LoginCodeOutcome: string
{
    case Accepted = 'accepted';
    case Wrong = 'wrong';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';
}
