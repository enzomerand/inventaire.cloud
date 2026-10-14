<?php

declare(strict_types=1);

use App\Installer\EnvironmentLocks;
use App\Installer\InstallerSettings;

/**
 * Boots a fresh instance whose process environment is the given one.
 *
 * @param  array<string, string>  $environment
 */
function freshInstanceWith(object $test, array $environment): void
{
    $test->bootInstaller(installed: false);
    app()->singleton(EnvironmentLocks::class, fn (): EnvironmentLocks => new EnvironmentLocks(fn (): array => $environment));
    app()->forgetInstance(InstallerSettings::class);
}

it('says installed once the instance is installed, whatever the environment provides', function (): void {
    $this->processEnvironment = containerEnvironment();

    $this->artisan('inventaire:install-status')->expectsOutput('installed')->assertExitCode(0);
});

it('says environment when every required setting comes from the environment', function (): void {
    freshInstanceWith($this, containerEnvironment());

    $this->artisan('inventaire:install-status')->expectsOutput('environment')->assertExitCode(0);
});

it('says wizard when the environment provides nothing, or only part of the configuration', function (array $environment): void {
    freshInstanceWith($this, $environment);

    $this->artisan('inventaire:install-status')->expectsOutput('wizard')->assertExitCode(0);
})->with([
    'nothing' => [[]],
    'only the database' => [fn (): array => array_intersect_key(containerEnvironment(), array_flip(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']))],
    'everything but the mail sender' => [fn (): array => array_diff_key(containerEnvironment(), ['MAIL_FROM_ADDRESS' => ''])],
    'everything but the administrator' => [fn (): array => array_diff_key(containerEnvironment(), ['ADMIN_NAME' => '', 'ADMIN_EMAIL' => ''])],
    'everything but the administrator address' => [fn (): array => array_diff_key(containerEnvironment(), ['ADMIN_EMAIL' => ''])],
]);

it('runs none of the installer checks', function (): void {
    // An unreachable database and mail server would make a real check fail: the command only reads the configuration.
    freshInstanceWith($this, ['DB_HOST' => 'unreachable.invalid'] + containerEnvironment());

    $this->artisan('inventaire:install-status')->expectsOutput('environment')->assertExitCode(0);
});
