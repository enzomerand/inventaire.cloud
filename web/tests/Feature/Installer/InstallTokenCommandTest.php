<?php

declare(strict_types=1);

use App\Installer\SetupToken;

it('prints only the setup token, creating it when needed', function (): void {
    $this->bootInstaller(installed: false);

    $this->artisan('inventaire:install-token')->assertSuccessful();

    $token = app(SetupToken::class)->current();
    expect($token)->toMatch('/^[0-9a-f]{32}$/');

    $this->artisan('inventaire:install-token')->expectsOutput($token)->assertSuccessful();
});

it('refuses once the instance is installed', function (): void {
    $this->artisan('inventaire:install-token')->assertFailed();

    expect(is_file(app(SetupToken::class)->path()))->toBeFalse();
});
