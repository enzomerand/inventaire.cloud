<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\EnvironmentLocks;

/**
 * Builds locks over a .env file with the given contents and a fake process environment.
 *
 * @param  array<string, string>  $process
 */
function environmentLocks(object $test, string $contents, array $process): EnvironmentLocks
{
    $path = $test->sandbox->root.'/lock-env';
    file_put_contents($path, $contents);

    return new EnvironmentLocks(new EnvironmentFile($path, $path), fn (): array => $process);
}

it('locks variables set in the environment but absent from .env', function (): void {
    $locks = environmentLocks($this, "APP_NAME=x\n", ['DB_HOST' => 'db']);

    expect($locks->isLocked('DB_HOST'))->toBeTrue()->and($locks->value('DB_HOST'))->toBe('db');
});

it('locks variables whose environment value differs from .env', function (): void {
    $locks = environmentLocks($this, "DB_HOST=127.0.0.1\n", ['DB_HOST' => 'db']);

    expect($locks->isLocked('DB_HOST'))->toBeTrue()->and($locks->value('DB_HOST'))->toBe('db');
});

it('does not lock values Laravel merely copied from .env', function (): void {
    $locks = environmentLocks($this, "DB_HOST=db\nMAIL_FROM_NAME=\"\${DB_HOST} mail\"\n", ['DB_HOST' => 'db', 'MAIL_FROM_NAME' => 'db mail']);

    expect($locks->isLocked('DB_HOST'))->toBeFalse()
        ->and($locks->isLocked('MAIL_FROM_NAME'))->toBeFalse()
        ->and($locks->value('DB_HOST'))->toBeNull();
});

it('does not lock variables the environment does not define', function (): void {
    $locks = environmentLocks($this, "DB_HOST=db\n", []);

    expect($locks->isLocked('DB_HOST'))->toBeFalse()->and($locks->isLocked('NOPE'))->toBeFalse();
});

it('keeps the verdict taken at creation even after .env is rewritten', function (): void {
    $path = $this->sandbox->root.'/lock-env';
    file_put_contents($path, "DB_HOST=old\n");
    $file = new EnvironmentFile($path, $path);

    // The process still holds the value it loaded at startup, as in a real request.
    $locks = new EnvironmentLocks($file, fn (): array => ['DB_HOST' => 'old']);
    $file->set(['DB_HOST' => 'new']);

    expect($locks->isLocked('DB_HOST'))->toBeFalse();
});

it('ignores non-scalar and numerically indexed environment entries', function (): void {
    $locks = environmentLocks($this, '', [0 => 'x', 'ARR' => ['a'], 'PORT' => 5432]);

    expect($locks->isLocked('ARR'))->toBeFalse()->and($locks->value('PORT'))->toBe('5432');
});
