<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The 6-digit codes people read from an email and type back: generating them
 * and hashing them for storage.
 *
 * Shared by the install wizard's mail check and by sign-in. A code is only a
 * few digits long, so it is never stored as is: what is kept is a keyed hash
 * (HMAC-SHA256 with the application key), which a leaked database or session
 * cannot turn back into the code without that key. Deciding whether a code is
 * still valid (expiry, attempts) belongs to each caller, which keeps its own
 * state.
 */
final class OneTimeCode
{
    /** Number of digits of a code. */
    public const LENGTH = 6;

    /**
     * A fresh random code, zero-padded ("004217").
     */
    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * The keyed hash to store for a code.
     *
     * @param  string  $key  Secret used to hash the code (the application key).
     */
    public static function hash(string $code, string $key): string
    {
        return hash_hmac('sha256', $code, $key);
    }

    /**
     * Whether a submitted code is the one a stored hash was made from, compared in constant time.
     * Whitespace around the code is ignored (people paste it from a mail).
     */
    public static function matches(string $submitted, string $hash, string $key): bool
    {
        return hash_equals($hash, self::hash(trim($submitted), $key));
    }
}
