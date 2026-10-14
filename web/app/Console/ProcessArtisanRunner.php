<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs artisan commands as child processes of the current PHP binary.
 */
final class ProcessArtisanRunner implements ArtisanRunner
{
    /** Seconds a command may take; compiling every view is the slowest. */
    private const TIMEOUT = 300;

    /**
     * Runs `php artisan <command>` and waits for it.
     *
     * @return array{0: int, 1: string}
     */
    public function run(string $command): array
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), $command, '--no-interaction'], base_path(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return [1, 'Timed out after '.self::TIMEOUT.' seconds.'];
        }

        return [$process->getExitCode() ?? 1, trim($process->getOutput().$process->getErrorOutput())];
    }
}
