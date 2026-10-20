<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Makes what a user wrote safe to put in the plain-text body of a mail.
 */
final class PlainText
{
    /**
     * The text on one line: what a user wrote (a name, a user agent) cannot add lines to the plain-text body, nor reorder what is
     * around it with direction characters (control and format characters, and every kind of space, become a single space).
     */
    public static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\p{C}\p{Z}]+/u', ' ', $text) ?? '');
    }
}
