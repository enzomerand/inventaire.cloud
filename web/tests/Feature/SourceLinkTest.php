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

it('reads the repository URL from the environment', function (): void {
    expect(file_get_contents(base_path('.env.example')))->toContain('APP_REPOSITORY_URL=');
});
