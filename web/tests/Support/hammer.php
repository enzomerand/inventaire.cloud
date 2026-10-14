<?php

// Child process of the concurrency tests: repeatedly does one change of a shared installer file.
// Usage: php hammer.php <what> <directory> <name> <iterations>

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Installer\InstallationState;
use App\Installer\InstallStep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $what, $directory, $name, $iterations] = $argv;

if ($what === 'progress') {
    $state = new InstallationState($directory);
    $step = InstallStep::from($name);

    for ($i = 0; $i < (int) $iterations; $i++) {
        $state->record($step);
    }
} else {
    $file = new EnvironmentFile($directory.'/.env', $directory.'/.env', null, $directory.'/env.lock');

    for ($i = 0; $i < (int) $iterations; $i++) {
        $file->set([$name => (string) $i]);
    }
}
