<?php

declare(strict_types=1);

namespace App\Legal;

use App\Landing\PricingPlans;

/**
 * Who sells the Pro plan, as `config/legal.php` (`LEGAL_*`) holds it: the details the terms of sale print.
 *
 * A sale needs terms that name the seller. {@see self::complete()} is true only when every required detail is filled in, and
 * {@see PricingPlans::selling()} requires it: an instance whose seller is incomplete does not sell anything, and its terms page is a working
 * version (a banner, no indexing). The values are read when asked, never kept, so a test (or an administrator who edits the environment
 * and restarts) is not served a stale answer.
 */
final class Seller
{
    /** What takes the place of a required detail that is not filled in. */
    public const MISSING = '[à compléter]';

    /** The details a sale cannot do without: every one of {@see self::details()} but the capital. */
    private const REQUIRED = ['name', 'form', 'address', 'registration', 'vat_number', 'email', 'phone', 'mediator_name', 'mediator_address', 'mediator_url'];

    /**
     * The details of the seller, as the terms print them: the capital is null when there is none, and a required detail that is not filled in
     * is {@see self::MISSING}.
     *
     * @return array{name: string, form: string, capital: string|null, address: string, registration: string, vat_number: string, email: string, phone: string, mediator_name: string, mediator_address: string, mediator_url: string}
     */
    public static function details(): array
    {
        $capital = self::value('capital');

        return [
            'name' => self::required('name'),
            'form' => self::required('form'),
            'capital' => $capital === '' ? null : $capital,
            'address' => self::required('address'),
            'registration' => self::required('registration'),
            'vat_number' => self::required('vat_number'),
            'email' => self::required('email'),
            'phone' => self::required('phone'),
            'mediator_name' => self::required('mediator_name'),
            'mediator_address' => self::required('mediator_address'),
            'mediator_url' => self::required('mediator_url'),
        ];
    }

    /**
     * Whether every required detail is filled in (blanks around a value do not count as one).
     */
    public static function complete(): bool
    {
        foreach (self::REQUIRED as $key) {
            if (self::value($key) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A required detail, or the mark that it is missing.
     */
    private static function required(string $key): string
    {
        $value = self::value($key);

        return $value === '' ? self::MISSING : $value;
    }

    /**
     * A detail of the configuration, trimmed; an empty text when it is not set.
     */
    private static function value(string $key): string
    {
        $value = config("legal.seller.{$key}");

        return is_string($value) ? trim($value) : '';
    }
}
