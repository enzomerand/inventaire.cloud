<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs PHP scripts in separate processes that all act at the same moment, and collects what each one reported.
 *
 * This is what a single process cannot reproduce: requests that read the same rows at the same time. Every child boots
 * first and says "ready"; once all are, the test creates the go file and they all act together, whatever the time each
 * one needed to boot. The scripts are written with {@see ConcurrentChild}, which is their side of this exchange.
 */
final class ConcurrentChildren
{
    /**
     * How long, in seconds, all the children have to boot and say "ready".
     */
    public const BOOT_TIMEOUT = 90;

    /**
     * How long, in seconds, a child may run in all, from its start to its answer.
     */
    public const RUN_TIMEOUT = 120;

    /**
     * Starts one child per command, waits until all are ready, lets them go and returns what each one printed.
     *
     * @param  string  $directory  A directory of this test alone, where the go file is created.
     * @param  string  $script  The script every child runs, which follows the protocol of {@see ConcurrentChild}.
     * @param  list<list<int|string>>  $commands  The arguments of each child after the go file.
     * @param  array<string, string>  $environment  Added to the environment the children inherit.
     * @return list<array<string, mixed>> The last JSON line each child printed, in the order of the commands: whatever
     *                                    else it printed ("ready", a warning...) is not its answer.
     *
     * @throws RuntimeException When a child does not get ready, or exits with an error.
     */
    public static function run(string $directory, string $script, array $commands, array $environment = []): array
    {
        $go = $directory.'/go';
        @unlink($go);
        $children = [];

        try {
            foreach ($commands as $command) {
                $child = new Process([PHP_BINARY, $script, $go, ...array_map('strval', $command)], base_path(), array_merge(getenv(), $_ENV, $environment));
                $child->setTimeout(self::RUN_TIMEOUT);
                $child->start();
                $children[] = $child;
            }

            self::awaitReady($children);
            touch($go);

            return array_map(self::answerOf(...), $children);
        } finally {
            @unlink($go);

            foreach ($children as $child) {
                $child->stop(0);
            }
        }
    }

    /**
     * Waits until every child has said "ready".
     *
     * @param  list<Process>  $children
     *
     * @throws RuntimeException When one stops before that, or the boot takes longer than {@see self::BOOT_TIMEOUT}.
     */
    private static function awaitReady(array $children): void
    {
        $deadline = microtime(true) + self::BOOT_TIMEOUT;

        foreach ($children as $child) {
            while (! str_contains($child->getOutput(), "ready\n")) {
                if (! $child->isRunning() || microtime(true) > $deadline) {
                    throw new RuntimeException('A child process did not get ready: '.$child->getOutput().$child->getErrorOutput());
                }

                usleep(5_000);
            }
        }
    }

    /**
     * Waits for a child to finish and reads its answer: the last JSON line it printed.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the child exits with an error.
     */
    private static function answerOf(Process $child): array
    {
        $child->wait();

        if ($child->getExitCode() !== 0) {
            throw new RuntimeException('A child process failed: '.$child->getOutput().$child->getErrorOutput());
        }

        $lines = array_values(array_filter(explode("\n", $child->getOutput()), fn (string $line): bool => str_starts_with($line, '{')));

        return json_decode($lines === [] ? '' : $lines[array_key_last($lines)], true, flags: JSON_THROW_ON_ERROR);
    }
}
