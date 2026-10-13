<?php

declare(strict_types=1);

use App\Installer\Settings\DatabaseSettings;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the whole Laravel application through the base TestCase.
| Unit tests stay framework-free on purpose: they must run without a container.
|
*/

pest()->extend(TestCase::class)->in('Feature');

// The installer's messages are asserted on their English source text.
pest()->in('Feature/Installer')->beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * Database settings pointing at the engine the suite currently runs on (the
 * dedicated test database), with optional overrides.
 *
 * @param  array<string, mixed>  $override
 */
function testDatabaseSettings(array $override = []): DatabaseSettings
{
    $driver = config('database.default');
    $connection = config("database.connections.{$driver}");

    return DatabaseSettings::fromArray(array_merge([
        'driver' => $driver,
        'host' => $connection['host'],
        'port' => $connection['port'],
        'database' => $connection['database'],
        'username' => $connection['username'],
        'password' => $connection['password'],
    ], $override));
}
