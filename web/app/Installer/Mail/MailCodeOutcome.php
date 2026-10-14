<?php

declare(strict_types=1);

namespace App\Installer\Mail;

/**
 * Result of checking a submitted verification code.
 */
enum MailCodeOutcome: string
{
    case Accepted = 'accepted';
    case Wrong = 'wrong';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';
}
