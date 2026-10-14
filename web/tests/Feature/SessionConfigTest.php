<?php

declare(strict_types=1);

/**
 * The `secure` setting config/session.php computes for given environment variables (null removes a variable).
 *
 * The configuration file is evaluated again, with the variables swapped for the duration of the call, since the
 * application read its own when it booted.
 *
 * @param  array<string, string|null>  $environment
 */
function secureCookieSetting(array $environment): mixed
{
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];

        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        if ($value !== null) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        return (require config_path('session.php'))['secure'];
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

describe('the session cookie', function (): void {
    it('is HTTPS-only by default when the instance lives at an https:// address', function (string $url): void {
        expect(secureCookieSetting(['APP_URL' => $url, 'SESSION_SECURE_COOKIE' => null]))->toBeTrue();
    })->with(['https://inventory.example.test', 'https://inventory.example.test:8443/app', 'HTTPS://INVENTORY.EXAMPLE.TEST']);

    it('is left as before when the instance lives at an http:// address, or at none', function (?string $url): void {
        expect(secureCookieSetting(['APP_URL' => $url, 'SESSION_SECURE_COOKIE' => null]))->toBeNull();
    })->with(['http://localhost', 'http://192.168.1.10:8080', 'inventory.example.test', null]);

    it('does what SESSION_SECURE_COOKIE says, whatever the address', function (string $url, string $setting, bool $expected): void {
        expect(secureCookieSetting(['APP_URL' => $url, 'SESSION_SECURE_COOKIE' => $setting]))->toBe($expected);
    })->with([
        'https but explicitly off' => ['https://inventory.example.test', 'false', false],
        'http but explicitly on' => ['http://localhost', 'true', true],
        'https and on' => ['https://inventory.example.test', 'true', true],
    ]);
});
