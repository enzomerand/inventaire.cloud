<?php

declare(strict_types=1);

use App\Inventories\Fields\Decimal;

describe('reading a number', function (): void {
    it('keeps the digits of what a client sends, in any of the accepted forms', function (mixed $value, string $minimal, string $padded): void {
        $number = Decimal::tryFrom($value);

        expect($number)->not->toBeNull()
            ->and($number->format())->toBe($minimal)
            ->and($number->format(3))->toBe($padded);
    })->with([
        'an integer' => [12, '12', '12.000'],
        'a float' => [12.5, '12.5', '12.500'],
        'a string with a dot' => ['12.50', '12.5', '12.500'],
        'a negative one' => ['-0.25', '-0.25', '-0.250'],
        'leading zeros' => ['007.5', '7.5', '7.500'],
        'zero with a sign is zero' => ['-0.00', '0', '0.000'],
        'negative float zero' => [-0.0, '0', '0.000'],
        'a float written with an exponent' => [1.5e-7, '0.00000015', '0.00000015'],
        'a float that is not exact in binary' => [0.1, '0.1', '0.100'],
        'a float PHP writes with an exponent' => [1.0e-5, '0.00001', '0.00001'],
        'a negative one' => [-2.5e-6, '-0.0000025', '-0.0000025'],
        'several digits, shifted exactly and not rounded' => [1.2345678901e-9, '0.0000000012345678901', '0.0000000012345678901'],
        'a large float, shifted exactly' => [2.5e20, '250000000000000000000', '250000000000000000000.000'],
    ]);

    it('refuses what is not a plain decimal number', function (mixed $value): void {
        expect(Decimal::tryFrom($value))->toBeNull();
    })->with([
        'an exponent' => '1e5',
        'a plus sign' => '+5',
        'a comma' => '12,5',
        'spaces' => ' 5',
        'a dot with nothing after it' => '5.',
        'a dot with nothing before it' => '.5',
        'text' => 'abc',
        'nothing' => '',
        'a boolean' => true,
        'null' => null,
        'a list' => [[1]],
        'a float too far from zero to be read exactly' => 1.0e-100,
        'a float too large to be read exactly' => 1.0e100,
        'infinity' => INF,
        'not a number' => NAN,
        'a trailing newline' => "5\n",
    ]);

    it('counts the decimals the number really has, not the zeros written after them', function (): void {
        expect(Decimal::tryFrom('12.500')->scale())->toBe(1)
            ->and(Decimal::tryFrom('12.0')->scale())->toBe(0)
            ->and(Decimal::tryFrom('0.000001')->scale())->toBe(6);
    });

    it('tells whether the number is below 1e15 in absolute value', function (): void {
        expect(Decimal::tryFrom('999999999999999')->fits())->toBeTrue()
            ->and(Decimal::tryFrom('-999999999999999.999999')->fits())->toBeTrue()
            ->and(Decimal::tryFrom('1000000000000000')->fits())->toBeFalse()
            ->and(Decimal::tryFrom(1e25)->fits())->toBeFalse();
    });
});

describe('comparing numbers', function (): void {
    it('orders them like numbers, not like text', function (string $left, string $right, int $expected): void {
        expect(Decimal::tryFrom($left)->compareTo(Decimal::tryFrom($right)))->toBe($expected)
            ->and(Decimal::tryFrom($right)->compareTo(Decimal::tryFrom($left)))->toBe(-$expected);
    })->with([
        'more integer digits is larger' => ['10', '9.99', 1],
        'equal numbers written differently' => ['0.1', '0.10', 0],
        'the first decimal that differs decides' => ['1.25', '1.3', -1],
        'negative below positive' => ['-1', '1', -1],
        'two negatives: the larger magnitude is smaller' => ['-1.5', '-1.25', -1],
        'zero has no sign' => ['-0', '0', 0],
        'a small fraction' => ['0.000001', '0', 1],
    ]);
});

describe('reading a float whatever the server is configured to write floats with', function (): void {
    afterEach(fn () => ini_set('serialize_precision', '-1'));

    it('gets the same digits with serialize_precision at 17 as at -1', function (float $value, string $expected): void {
        ini_set('serialize_precision', '17');

        // What PHP itself would write under that setting: the digits of the binary value, not the number that was typed.
        expect(json_encode(0.1))->toBe('0.10000000000000001')
            ->and(Decimal::tryFrom($value)->format())->toBe($expected);
    })->with([
        'a tenth' => [0.1, '0.1'],
        'two and a half' => [2.5, '2.5'],
        'a price' => [19.99, '19.99'],
        'a small float with an exponent' => [1.0e-5, '0.00001'],
        'a negative one' => [-0.3, '-0.3'],
    ]);

    it('puts the setting back as it found it, also when it was not the default', function (): void {
        ini_set('serialize_precision', '17');
        Decimal::tryFrom(0.1);

        expect(ini_get('serialize_precision'))->toBe('17');

        ini_set('serialize_precision', '-1');
        Decimal::tryFrom(0.1);

        expect(ini_get('serialize_precision'))->toBe('-1');
    });
});
