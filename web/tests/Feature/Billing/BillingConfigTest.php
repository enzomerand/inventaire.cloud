<?php

declare(strict_types=1);

/*
 * How `config/billing.php` reads the environment: the booleans are read the way every other boolean of the application is, so that a
 * variable set to `false`, `0`, `no` or `off` turns a thing off instead of being the non-empty text it is for a plain cast.
 */

/**
 * The billing configuration as the environment gives it, evaluated again with the variables swapped for the duration of the call (the
 * application read its own when it booted).
 *
 * @param  array<string, string|null>  $variables  The variables to set; null removes one.
 * @return array<string, mixed>
 */
function billingConfigRead(array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        if ($value !== null) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path('billing.php');

        return $config;
    } finally {
        foreach ($previous as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    }
}

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
