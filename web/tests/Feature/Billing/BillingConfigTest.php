<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/billing.php';

/*
 * How `config/billing.php` reads the environment: the booleans are read the way every other boolean of the application is, so that a
 * variable set to `false`, `0`, `no` or `off` turns a thing off instead of being the non-empty text it is for a plain cast.
 */

describe('the booleans', function (): void {
    it('reads STRIPE_AUTOMATIC_TAX like the other booleans: only a word for yes turns it on', function (?string $value, bool $expected): void {
        expect(billingConfigRead(['STRIPE_AUTOMATIC_TAX' => $value])['automatic_tax'])->toBe($expected);
    })->with([
        'not set' => [null, false],
        'true' => ['true', true],
        'TRUE' => ['TRUE', true],
        '1' => ['1', true],
        'yes' => ['yes', true],
        'on' => ['on', true],
        'false' => ['false', false],
        '0' => ['0', false],
        'no' => ['no', false],
        'off' => ['off', false],
        'empty' => ['', false],
        'something else' => ['maybe', false],
    ]);

    it('reads the photo entry of the plans the same way', function (?string $value, bool $free, bool $pro): void {
        $config = billingConfigRead(['BILLING_FREE_PHOTO_ENTRY' => $value, 'BILLING_PRO_PHOTO_ENTRY' => $value]);

        expect($config['plans']['free']['features']['photo_entry'])->toBe($free)
            ->and($config['plans']['pro']['features']['photo_entry'])->toBe($pro);
    })->with([
        'not set: Pro only' => [null, false, true],
        'yes for both' => ['true', true, true],
        'no for both' => ['false', false, false],
        'off' => ['off', false, false],
    ]);
});

describe('a variable left blank', function (): void {
    // `BILLING_PRO_ITEMS=` in an environment file is a variable that is not set: `(int) ''` would be 0, and a limit of 0 refuses everything.

    $integers = [
        'free inventories' => ['BILLING_FREE_INVENTORIES', 'plans.free.limits.inventories', 3],
        'free items' => ['BILLING_FREE_ITEMS', 'plans.free.limits.items', 500],
        'free members' => ['BILLING_FREE_MEMBERS', 'plans.free.limits.members', 3],
        'free share links' => ['BILLING_FREE_SHARE_LINKS', 'plans.free.limits.share_links', 2],
        'pro inventories' => ['BILLING_PRO_INVENTORIES', 'plans.pro.limits.inventories', 100],
        'pro items' => ['BILLING_PRO_ITEMS', 'plans.pro.limits.items', 10000],
        'pro members' => ['BILLING_PRO_MEMBERS', 'plans.pro.limits.members', 50],
        'pro share links' => ['BILLING_PRO_SHARE_LINKS', 'plans.pro.limits.share_links', 20],
        'monthly amount' => ['BILLING_PRO_MONTHLY_AMOUNT', 'amounts.month', 600],
        'yearly amount' => ['BILLING_PRO_YEARLY_AMOUNT', 'amounts.year', 6000],
    ];

    it('takes the default of a number, as a variable that is not set', function (string $name, string $path, int $default): void {
        foreach (['', '   '] as $blank) {
            expect(data_get(billingConfigRead([$name => $blank]), $path))->toBe($default);
        }

        expect(data_get(billingConfigRead([$name => null]), $path))->toBe($default);
    })->with($integers);

    it('keeps a zero that was written: allowing nothing is a choice', function (string $name, string $path): void {
        expect(data_get(billingConfigRead([$name => '0']), $path))->toBe(0);
    })->with(array_map(fn (array $row): array => [$row[0], $row[1]], $integers));

    it('reads the number that was written', function (string $name, string $path): void {
        expect(data_get(billingConfigRead([$name => '42']), $path))->toBe(42)
            ->and(data_get(billingConfigRead([$name => ' 7 ']), $path))->toBe(7);
    })->with(array_map(fn (array $row): array => [$row[0], $row[1]], $integers));

    it('takes the default for something that is not a number either, rather than a limit of nothing', function (): void {
        $config = billingConfigRead(['BILLING_PRO_ITEMS' => 'unlimited', 'BILLING_PRO_MONTHLY_AMOUNT' => '6 euros']);

        expect($config['plans']['pro']['limits']['items'])->toBe(10000)->and($config['amounts']['month'])->toBe(600);
    });

    it('takes the default of a storage size in megabytes, which may have decimals, and keeps a zero', function (): void {
        $blank = billingConfigRead(['BILLING_FREE_STORAGE_MB' => '', 'BILLING_PRO_STORAGE_MB' => '  ']);
        $written = billingConfigRead(['BILLING_FREE_STORAGE_MB' => '0.5', 'BILLING_PRO_STORAGE_MB' => '0']);

        expect($blank['plans']['free']['limits']['storage_bytes'])->toBe(200 * 1048576)
            ->and($blank['plans']['pro']['limits']['storage_bytes'])->toBe(10240 * 1048576)
            ->and($written['plans']['free']['limits']['storage_bytes'])->toBe(524288)
            ->and($written['plans']['pro']['limits']['storage_bytes'])->toBe(0);
    });

    it('takes the default of the currency, and writes it in lowercase', function (): void {
        expect(billingConfigRead(['BILLING_CURRENCY' => ''])['currency'])->toBe('eur')
            ->and(billingConfigRead(['BILLING_CURRENCY' => ' '])['currency'])->toBe('eur')
            ->and(billingConfigRead(['BILLING_CURRENCY' => 'USD'])['currency'])->toBe('usd')
            ->and(billingConfigRead(['BILLING_CURRENCY' => null])['currency'])->toBe('eur');
    });

    it('takes the default of a yes or a no: the photo entry stays in Pro, and out of Free', function (): void {
        $config = billingConfigRead(['BILLING_FREE_PHOTO_ENTRY' => '', 'BILLING_PRO_PHOTO_ENTRY' => ' ', 'STRIPE_AUTOMATIC_TAX' => '']);

        expect($config['plans']['free']['features']['photo_entry'])->toBeFalse()
            ->and($config['plans']['pro']['features']['photo_entry'])->toBeTrue()
            ->and($config['automatic_tax'])->toBeFalse();
    });
});
