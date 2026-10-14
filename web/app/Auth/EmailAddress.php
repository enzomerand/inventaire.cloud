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
     * Longest address there is (RFC 5321: 254 characters in all), and the longest {@see self::rules()} accepts.
     */
    public const MAX_LENGTH = 254;

    /**
     * The canonical form of an address: trimmed, in lowercase, with the domain
     * converted to ASCII (`gmäil.com` becomes `xn--gmil-moa.com`).
     *
     * It never fails: what cannot be converted comes back as typed (in
     * lowercase), and {@see self::rules()} refuses it. Normalizing a
     * normalized address changes nothing. An address longer than
     * {@see self::MAX_LENGTH} bytes is not worth converting, since it is refused
     * whatever its form: it only comes back trimmed, so that a request carrying
     * megabytes in place of an address costs next to nothing.
     */
    public static function normalize(string $email): string
    {
        $email = trim($email);

        if (strlen($email) > self::MAX_LENGTH) {
            return $email;
        }

        $email = mb_strtolower($email);
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
     * Whether the text is too long to be an address, which {@see self::rules()} refuses
     * and nothing has to be computed for (hash, conversion...).
     */
    public static function isTooLong(string $email): bool
    {
        return strlen($email) > self::MAX_LENGTH;
    }

    /**
     * Validation rules of an address that is to be normalized first (see {@see self::normalize()}).
     *
     * The length is checked first, and the other rules do not run on an address
     * that fails it: the `email` rule is not meant to parse megabytes.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['bail', 'required', 'string', 'max:'.self::MAX_LENGTH, 'email', new AsciiEmailAddress];
    }

    /**
     * Whether the text holds a character outside plain ASCII.
     */
    public static function hasNonAscii(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1;
    }
}
