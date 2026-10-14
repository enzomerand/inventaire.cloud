<?php

declare(strict_types=1);

use App\Services\TrustedProxies;

it('trusts nobody by default', function (): void {
    expect(TrustedProxies::parse(''))->toBe([])->and(TrustedProxies::parse('  '))->toBe([]);
});

it('reads a comma-separated list of addresses and ranges', function (): void {
    expect(TrustedProxies::parse('10.0.0.1, 192.168.0.0/16 ,,::1'))->toBe(['10.0.0.1', '192.168.0.0/16', '::1']);
});

it('keeps the wildcard as is', function (string $setting): void {
    expect(TrustedProxies::parse($setting))->toBe(trim($setting));
})->with(['*', ' * ', '**']);
