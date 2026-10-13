<?php

declare(strict_types=1);

/*
 * AGPL-3.0 section 13: whoever runs a modified version as a network service must
 * offer its users the corresponding source. The footer link therefore follows
 * APP_REPOSITORY_URL, so a fork never advertises the upstream repository.
 */

it('links the footer to the upstream repository by default', function (): void {
    $this->get(route('home'))
        ->assertSee('href="https://github.com/enzomerand/inventaire.cloud"', false)
        ->assertSee('href="https://github.com/enzomerand/inventaire.cloud/blob/HEAD/LICENSE"', false);
});

it('derives both footer links from the configured repository URL', function (): void {
    config(['app.repository_url' => 'https://git.example.org/acme/stock/']);

    $this->get(route('home'))
        ->assertSee('href="https://git.example.org/acme/stock"', false)
        ->assertSee('href="https://git.example.org/acme/stock/blob/HEAD/LICENSE"', false)
        ->assertDontSee('github.com/enzomerand', false);
});

it('falls back to the upstream repository when APP_REPOSITORY_URL is empty', function (): void {
    $name = 'APP_REPOSITORY_URL';
    $previous = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null, 'putenv' => getenv($name)];

    // Blank the variable everywhere the environment repository may read it.
    putenv("{$name}=");
    $_ENV[$name] = $_SERVER[$name] = '';

    try {
        // Proves the override is seen: otherwise the next assertion could pass on the .env value alone.
        expect(env($name))->toBe('');

        $config = require config_path('app.php');
    } finally {
        $previous['putenv'] === false ? putenv($name) : putenv("{$name}={$previous['putenv']}");

        if ($previous['env'] === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $previous['env'];
        }

        if ($previous['server'] === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $previous['server'];
        }
    }

    expect($config['repository_url'])->toBe('https://github.com/enzomerand/inventaire.cloud');
});
