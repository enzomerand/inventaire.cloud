<?php

declare(strict_types=1);

namespace App\Console;

use Closure;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs artisan commands as child processes of the current PHP binary.
 *
 * Whatever goes wrong with the child (it times out, is killed by the system, or
 * cannot even be started) is reported as a failed run with a message, never as
 * an exception: callers rebuild caches that the application can do without, so
 * a failure must not abort them.
 */
final class ProcessArtisanRunner implements ArtisanRunner
{
    /** Seconds a command may take; compiling every view is the slowest. */
    private const TIMEOUT = 300;

    /** @var (Closure(string): Process)|null */
    private readonly ?Closure $factory;

    /**
     * @param  (Closure(string): Process)|null  $factory  Builds the process that runs a command; by default, the current PHP binary running `artisan`. Replaced by tests to simulate a child that misbehaves.
     */
    public function __construct(?Closure $factory = null)
    {
        $this->factory = $factory;
    }

    /**
     * Runs `php artisan <command>` and waits for it.
     *
     * @return array{0: int, 1: string} The exit code (1 when the process could not run to its end) and everything it printed, or the reason it failed.
     */
    public function run(string $command): array
    {
        try {
            $process = ($this->factory ?? self::artisan(...))($command);

            $process->run();
        } catch (ProcessTimedOutException $exception) {
            return [1, self::describe('Timed out after '.(int) $exception->getProcess()->getTimeout().' seconds.', $exception->getProcess())];
        } catch (ProcessSignaledException $exception) {
            return [1, self::describe('Stopped by signal '.$exception->getSignal().': the system killed the process, possibly because it ran out of memory.', $exception->getProcess())];
        } catch (ExceptionInterface $exception) {
            // Includes ProcessStartFailedException (proc_open refused to start the process) and the
            // other failures to set one up (missing working directory, proc_open disabled...).
            return [1, 'Could not start PHP: '.self::lastLine($exception->getMessage())];
        }

        return [$process->getExitCode() ?? 1, trim($process->getOutput().$process->getErrorOutput())];
    }

    /**
     * The process that runs an artisan command with the current PHP binary.
     */
    private static function artisan(string $command): Process
    {
        return new Process([PHP_BINARY, base_path('artisan'), $command, '--no-interaction'], base_path(), timeout: self::TIMEOUT);
    }

    /**
     * A failure message followed by whatever the process printed before it failed.
     */
    private static function describe(string $reason, Process $process): string
    {
        $printed = trim($process->getOutput().$process->getErrorOutput());

        return $printed === '' ? $reason : $reason."\n".$printed;
    }

    /**
     * The last non-empty line of a message: where Symfony puts the actual error
     * of a process that failed to start, after the command and its directory.
     */
    private static function lastLine(string $message): string
    {
        $lines = explode("\n", trim($message));

        return trim($lines[array_key_last($lines)]);
    }
}
