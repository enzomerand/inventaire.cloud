<?php

declare(strict_types=1);

use App\Services\OneTimeCode;

it('generates six digits, zero-padded', function (): void {
    foreach (range(1, 300) as $ignored) {
        expect(OneTimeCode::generate())->toMatch('/^\d{6}$/');
    }
});

it('does not always generate the same code', function (): void {
    $codes = array_map(fn () => OneTimeCode::generate(), range(1, 50));

    expect(count(array_unique($codes)))->toBeGreaterThan(40);
});

it('hashes a code with HMAC-SHA256 under the key', function (): void {
    expect(OneTimeCode::hash('123456', 'key'))->toBe(hash_hmac('sha256', '123456', 'key'))
        ->and(OneTimeCode::hash('123456', 'key'))->not->toContain('123456');
});

it('recognizes the code a hash was made from, ignoring surrounding whitespace', function (): void {
    $hash = OneTimeCode::hash('004217', 'key');

    expect(OneTimeCode::matches('004217', $hash, 'key'))->toBeTrue()
        ->and(OneTimeCode::matches(" 004217\n", $hash, 'key'))->toBeTrue()
        ->and(OneTimeCode::matches('4217', $hash, 'key'))->toBeFalse()
        ->and(OneTimeCode::matches('004217', $hash, 'another-key'))->toBeFalse();
});
