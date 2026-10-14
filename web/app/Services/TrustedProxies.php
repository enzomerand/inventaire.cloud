<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Which reverse proxies the application believes about the client's address.
 *
 * Behind a proxy or a load balancer every request seems to come from the
 * proxy, so anything keyed on the client address (the setup token throttle,
 * rate limits) would see one single visitor. A proxy may only be believed when
 * it is named: trusting everybody would let any visitor forge the address with
 * an X-Forwarded-For header. The list comes from the TRUSTED_PROXIES variable
 * and is empty by default.
 */
final class TrustedProxies
{
    /**
     * Turns the value of TRUSTED_PROXIES into what Laravel expects.
     *
     * @param  string  $setting  Comma-separated addresses or CIDR ranges, "*" for every proxy, or empty for none.
     * @return list<string>|string
     */
    public static function parse(string $setting): array|string
    {
        $setting = trim($setting);

        if ($setting === '*' || $setting === '**') {
            return $setting;
        }

        return array_values(array_filter(array_map('trim', explode(',', $setting)), static fn (string $proxy): bool => $proxy !== ''));
    }

    /**
     * Applies the configured list to the framework's TrustProxies middleware.
     *
     * @param  array<string>|string  $proxies
     */
    public static function apply(array|string $proxies): void
    {
        TrustProxies::at($proxies);
    }
}
