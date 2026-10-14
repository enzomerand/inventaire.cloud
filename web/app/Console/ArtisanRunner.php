<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Runs an artisan command in a process of its own.
 *
 * Some commands only work in a clean process: the Blade icons package registers
 * its components before `view:cache` compiles the views only when that command
 * is the one artisan was started with, and `config:cache` and `route:cache`
 * boot a second application inside the current process. A command that has to
 * rebuild such a cache (see `inventaire:upgrade`) goes through this interface.
 */
interface ArtisanRunner
{
    /**
     * Runs `php artisan <command>` and waits for it.
     *
     * @return array{0: int, 1: string} The exit code, and everything the command printed (output and errors).
     */
    public function run(string $command): array;
}
