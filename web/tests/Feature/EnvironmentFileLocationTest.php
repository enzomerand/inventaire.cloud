<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Boots the real application in a fresh PHP process, the way a container
 * does, and returns what the script printed (JSON).
 *
 * The location of the configuration file is decided while bootstrap/app.php
 * runs, before anything of the test application exists, so it can only be
 * observed from the outside.
 *
 * @param  array<string, string|false>  $environment  Variables for the process; false removes one inherited from phpunit.xml.
 * @return array{process: Process, output: mixed} The process, and its output decoded from JSON (null when it is not JSON).
 */
function bootInProcess(string $script, array $environment): array
{
    $code = <<<'PHP'
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    PHP;

    $process = new Process([PHP_BINARY, '-r', $code."\n".$script, base_path()], base_path(), $environment + ['INSTALLER_ENV_FILE' => false]);
    $process->run();

    return ['process' => $process, 'output' => json_decode(trim($process->getOutput()), true)];
}

/**
 * A throw-away folder for a configuration file, removed by the caller.
 */
function temporaryEnvironmentFolder(object $test): string
{
    $folder = $test->sandbox->root.'/volume';
    mkdir($folder, 0700, true);

    return $folder;
}

it('loads the file named by APP_ENVIRONMENT_FILE instead of .env', function (): void {
    $folder = temporaryEnvironmentFolder($this);
    file_put_contents($folder.'/config.env', "INVENTAIRE_LOCATION_PROBE=from-the-volume\n");

    $result = bootInProcess('echo json_encode([$app->environmentFilePath(), env("INVENTAIRE_LOCATION_PROBE")]);', [
        'APP_ENVIRONMENT_FILE' => $folder.'/config.env',
        'INSTALLER_STATE_PATH' => $folder.'/state',
    ]);

    expect($result['process']->getExitCode())->toBe(0, $result['process']->getErrorOutput())
        ->and($result['output'])->toBe([$folder.'/config.env', 'from-the-volume']);
});

it('lets the real environment win over the file, as for .env', function (): void {
    $folder = temporaryEnvironmentFolder($this);
    file_put_contents($folder.'/config.env', "INVENTAIRE_LOCATION_PROBE=from-the-file\n");

    $result = bootInProcess('echo json_encode(env("INVENTAIRE_LOCATION_PROBE"));', [
        'APP_ENVIRONMENT_FILE' => $folder.'/config.env',
        'INSTALLER_STATE_PATH' => $folder.'/state',
        'INVENTAIRE_LOCATION_PROBE' => 'from-the-process',
    ]);

    expect($result['output'])->toBe('from-the-process');
});

it('starts without the file: it is created by the installer later', function (): void {
    $folder = temporaryEnvironmentFolder($this);

    $result = bootInProcess('echo json_encode([$app->environmentFilePath(), env("INVENTAIRE_LOCATION_PROBE")]);', [
        'APP_ENVIRONMENT_FILE' => $folder.'/missing.env',
        'INSTALLER_STATE_PATH' => $folder.'/state',
    ]);

    expect($result['output'])->toBe([$folder.'/missing.env', null]);
});

it('keeps the default file when APP_ENVIRONMENT_FILE is unset or empty', function (mixed $value): void {
    $result = bootInProcess('echo json_encode($app->environmentFilePath());', [
        'APP_ENVIRONMENT_FILE' => $value,
        'INSTALLER_STATE_PATH' => temporaryEnvironmentFolder($this).'/state',
    ]);

    // The test environment loads .env.testing: the path is still the one next to the application.
    expect($result['output'])->toBe(base_path('.env.testing'));
})->with([false, '']);

it('refuses a relative APP_ENVIRONMENT_FILE instead of silently ignoring it', function (): void {
    $result = bootInProcess('echo "booted";', [
        'APP_ENVIRONMENT_FILE' => 'storage/.env',
        'INSTALLER_STATE_PATH' => temporaryEnvironmentFolder($this).'/state',
    ]);

    expect($result['process']->getExitCode())->not->toBe(0)
        ->and($result['process']->getOutput().$result['process']->getErrorOutput())->toContain('APP_ENVIRONMENT_FILE must be an absolute path');
});

it('makes the installer read and write the file Laravel loads', function (): void {
    $folder = temporaryEnvironmentFolder($this);
    file_put_contents($folder.'/config.env', "INVENTAIRE_LOCATION_PROBE=before\n");

    $script = <<<'PHP'
    $file = $app->make(App\Installer\EnvironmentFile::class);
    $file->set(['INVENTAIRE_LOCATION_WRITTEN' => 'by-the-installer']);
    echo json_encode([$file->path(), $file->get('INVENTAIRE_LOCATION_PROBE')]);
    PHP;

    $result = bootInProcess($script, [
        'APP_ENVIRONMENT_FILE' => $folder.'/config.env',
        'INSTALLER_STATE_PATH' => $folder.'/state',
    ]);

    expect($result['process']->getExitCode())->toBe(0, $result['process']->getErrorOutput())
        ->and($result['output'])->toBe([$folder.'/config.env', 'before'])
        ->and(file_get_contents($folder.'/config.env'))->toContain('INVENTAIRE_LOCATION_PROBE=before')->toContain('INVENTAIRE_LOCATION_WRITTEN=by-the-installer');
});
