<?php

declare(strict_types=1);

namespace App\Auth;

use App\Rules\AsciiEmailAddress;

/**
 * The one canonical form of an email address, and the rules an address must pass.
 *
 * Every path that stores, looks up or compares an address goes through
 * {@see self::normalize()}: the requests, the account lookups, the pending
 * sign-in codes, the limiter keys, the installer and `inventaire:make-admin`.
 * Two spellings of one address must give one string, and two addresses that
 * merely look alike must give two: MySQL and MariaDB's default collation
 * ignores accents, so `john@gmäil.com` would otherwise open the account of
 * `john@gmail.com`. The domain is therefore stored in its ASCII (punycode)
 * form, and an accent in the local part is refused ({@see AsciiEmailAddress}).
 */
final class EmailAddress
{
    /**
     * The canonical form of an address: trimmed, in lowercase, with the domain
     * converted to ASCII (`gmäil.com` becomes `xn--gmil-moa.com`).
     *
     * It never fails: what cannot be converted comes back as typed (in
     * lowercase), and {@see self::rules()} refuses it. Normalizing a
     * normalized address changes nothing.
     */
    public static function normalize(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $domain = substr($email, $at + 1);

        if ($domain === '' || ! self::hasNonAscii($domain)) {
            return $email;
        }

        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false || $ascii === '' ? $email : substr($email, 0, $at + 1).$ascii;
    }

    /**
     * Validation rules of an address that is to be normalized first (see {@see self::normalize()}).
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:255', 'email', new AsciiEmailAddress];
    }

    /**
     * Whether the text holds a character outside plain ASCII.
     */
    public static function hasNonAscii(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1;
    }
}
