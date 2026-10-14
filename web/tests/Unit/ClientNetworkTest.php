<?php

declare(strict_types=1);

use App\Services\ClientNetwork;

describe('the client an IP address stands for', function (): void {
    it('is the address itself for IPv4', function (): void {
        expect(ClientNetwork::key('203.0.113.7'))->toBe('203.0.113.7')
            ->and(ClientNetwork::key('203.0.113.8'))->not->toBe(ClientNetwork::key('203.0.113.7'));
    });

    it('is the /64 prefix for IPv6, whatever the way the address is written', function (string $address): void {
        expect(ClientNetwork::key($address))->toBe('2001:db8:aaaa:1::/64');
    })->with([
        'compressed' => ['2001:db8:aaaa:1::1'],
        'the last address of the network' => ['2001:db8:aaaa:1:ffff:ffff:ffff:ffff'],
        'capitals' => ['2001:DB8:AAAA:1:ABCD:EF01:2345:6789'],
        'written in full' => ['2001:0db8:aaaa:0001:0000:0000:0000:0042'],
    ]);

    it('tells two IPv6 networks apart', function (): void {
        expect(ClientNetwork::key('2001:db8:aaaa:1::1'))->not->toBe(ClientNetwork::key('2001:db8:aaaa:2::1'))
            ->and(ClientNetwork::key('2001:db8:aaaa:1::1'))->not->toBe(ClientNetwork::key('2001:db9:aaaa:1::1'));
    });

    it('is the IPv4 address for an IPv4 client on a dual-stack socket', function (): void {
        expect(ClientNetwork::key('::ffff:203.0.113.7'))->toBe('203.0.113.7');
    });

    it('is a value of its own when there is no address, and the text when it is not an address', function (): void {
        expect(ClientNetwork::key(null))->toBe('unknown')
            ->and(ClientNetwork::key(''))->toBe('unknown')
            ->and(ClientNetwork::key('not-an-ip'))->toBe('not-an-ip');
    });
});
