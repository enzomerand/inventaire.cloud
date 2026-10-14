<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tells which "client" an IP address stands for when requests are counted.
 *
 * A person on IPv6 does not have one address: a home connection or a mobile
 * subscriber receives a whole /64 and can change address inside it at will, so
 * counting each address separately would give an attacker as many fresh
 * allowances as they like. Addresses are therefore grouped by their /64
 * prefix; an IPv4 address (or an IPv4 one written in IPv6 form) stands for itself.
 */
final class ClientNetwork
{
    /** Number of leading bytes of an IPv6 address that identify its network (a /64). */
    private const IPV6_PREFIX_BYTES = 8;

    /**
     * The key that counts as one client: the address itself for IPv4, the /64 prefix for IPv6.
     *
     * @param  string|null  $ip  Address of the request, as given by the framework.
     */
    public static function key(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        if (strlen($packed) === 4) {
            return $ip;
        }

        // ::ffff:a.b.c.d is an IPv4 client reaching a dual-stack socket.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, self::IPV6_PREFIX_BYTES).str_repeat("\0", 16 - self::IPV6_PREFIX_BYTES)).'/64';
    }
}
