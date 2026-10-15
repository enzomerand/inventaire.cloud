<?php

declare(strict_types=1);

namespace App\Mail;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the sending of a mail once the response has been sent, so that nobody waits for the mail server and the answer
 * does not depend on it.
 *
 * It rides on Laravel's `defer()`: the work runs when the request terminates, after the response left. It is not the
 * queue, on purpose: a queued job is stored in clear in the `jobs` table (and in `failed_jobs` when it fails), and the
 * mails sent through here carry sign-in and deletion codes, which the application only ever keeps as hashes.
 *
 * What it cannot promise, and the caller has to be fine with:
 *
 * - the work runs only for a successful response (below 400), and only if the process lives long enough to terminate
 *   the request: a crash or a kill in between loses the mail, and whoever waited for it asks again;
 * - when the server cannot finish a response before the script ends (PHP's built-in server and `artisan serve`, which
 *   are development servers), the visitor's connection stays open until the work is done, as if it had been done
 *   before the answer. FrankenPHP (the Docker image), PHP-FPM and LiteSpeed answer first;
 * - the sending can fail, and nobody is told: the failure is logged (without any email address), and the caller's
 *   cleanup runs (a code nobody received is deleted, so that the person just asks for another one).
 */
final class AfterResponse
{
    /**
     * Schedules the work for after the response.
     *
     * @param  string  $description  What is being sent, for the log ("a sign-in code").
     * @param  Closure(): void  $work  Sends the mail. It may decide not to.
     * @param  (Closure(): void)|null  $onFailure  Cleans up when the work threw (a pending code to forget).
     */
    public static function run(string $description, Closure $work, ?Closure $onFailure = null): void
    {
        defer(function () use ($description, $work, $onFailure): void {
            try {
                $work();
            } catch (Throwable $exception) {
                self::failed($description, $exception, $onFailure);
            }
        });
    }

    /**
     * Logs a failure and runs the cleanup, which must not make it worse.
     *
     * What is logged is the kind of failure and what the mail server said, with every email address replaced: a mail
     * server's answer often names the recipient ("550 5.1.1 <ada@example.com>: Recipient address rejected"), and an
     * address is personal data that has no business in a log. Never the content of the mail, which may be a code.
     */
    private static function failed(string $description, Throwable $exception, ?Closure $onFailure): void
    {
        Log::error("Could not send {$description}: ".$exception::class.': '.self::withoutAddresses($exception->getMessage()));

        if ($onFailure === null) {
            return;
        }

        try {
            $onFailure();
        } catch (Throwable $cleanup) {
            Log::error("Could not clean up after failing to send {$description}: ".$cleanup::class.': '.self::withoutAddresses($cleanup->getMessage()));
        }
    }

    /**
     * The text with every email address (and what surrounds it up to the next space) replaced by "[address]".
     */
    private static function withoutAddresses(string $text): string
    {
        return preg_replace('/\S*@\S*/u', '[address]', $text) ?? '[message not readable]';
    }
}
