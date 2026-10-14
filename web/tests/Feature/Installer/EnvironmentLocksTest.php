<?php

declare(strict_types=1);

use App\Installer\EnvironmentLocks;
use App\Installer\ProcessEnvironment;

it('locks every variable the process started with', function (): void {
    $locks = new EnvironmentLocks(fn (): array => ['DB_HOST' => 'db']);

    expect($locks->isLocked('DB_HOST'))->toBeTrue()->and($locks->value('DB_HOST'))->toBe('db');
});

it('does not lock variables the environment does not define', function (): void {
    $locks = new EnvironmentLocks(fn (): array => ['DB_HOST' => 'db']);

    expect($locks->isLocked('DB_PORT'))->toBeFalse()->and($locks->value('DB_PORT'))->toBeNull();
});

it('locks a variable even when .env holds the very same value', function (): void {
    // The snapshot is taken before .env is loaded: an equal value in .env is still the environment's.
    file_put_contents($this->sandbox->envPath(), "DB_HOST=db\n");

    $locks = new EnvironmentLocks(fn (): array => ['DB_HOST' => 'db']);

    expect($locks->isLocked('DB_HOST'))->toBeTrue();
});

it('ignores non-scalar and numerically indexed entries', function (): void {
    $locks = new EnvironmentLocks(fn (): array => [0 => 'x', 'ARR' => ['a'], 'PORT' => 5432]);

    expect($locks->isLocked('ARR'))->toBeFalse()->and($locks->value('PORT'))->toBe('5432')->and($locks->isLocked('0'))->toBeFalse();
});

describe('the process environment snapshot', function (): void {
    it('contains the variables of the process, not the headers a visitor sends', function (): void {
        $variables = ProcessEnvironment::variables();

        expect($variables)->not->toBeEmpty()->and(array_filter(array_keys($variables), fn (string $name): bool => str_starts_with($name, 'HTTP_')))->toBe([]);
    });

    it('is taken once, before .env is loaded, and survives later changes', function (): void {
        $before = ProcessEnvironment::variables();

        putenv('INSTALLER_SNAPSHOT_PROBE=late');

        try {
            expect(ProcessEnvironment::variables())->toBe($before)->and(array_key_exists('INSTALLER_SNAPSHOT_PROBE', ProcessEnvironment::variables()))->toBeFalse();
        } finally {
            putenv('INSTALLER_SNAPSHOT_PROBE');
        }
    });

    it('is captured at the very top of bootstrap/app.php', function (): void {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        expect(strpos($bootstrap, 'ProcessEnvironment::capture()'))->toBeLessThan(strpos($bootstrap, 'Application::configure'));
    });
});
