<?php

declare(strict_types=1);

use App\Mail\AfterResponse;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/*
 * The sending of a mail once the response has gone: what runs, when, and what is left in the log when it fails.
 * (The mails themselves are tested where they are sent: sign-in codes, deletion codes, the welcome mail.)
 */

it('runs the work when the request ends, not when it is scheduled', function (): void {
    $deferred = holdDeferred();
    $ran = false;

    AfterResponse::run('a test mail', function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeFalse()->and($deferred->count())->toBe(1);

    $deferred->release();

    expect($ran)->toBeTrue();
});

it('says nothing when the work succeeds, and does not run the cleanup', function (): void {
    $deferred = holdDeferred();
    $cleaned = false;
    Log::spy();

    AfterResponse::run('a test mail', fn () => null, function () use (&$cleaned): void {
        $cleaned = true;
    });
    $deferred->release();

    expect($cleaned)->toBeFalse();
    Log::shouldNotHaveReceived('error');
});

it('logs a failure, runs the cleanup, and does not let the failure reach the request', function (): void {
    $deferred = holdDeferred();
    $cleaned = false;
    Log::spy();

    AfterResponse::run('a test mail', function (): void {
        throw new RuntimeException('Connection refused');
    }, function () use (&$cleaned): void {
        $cleaned = true;
    });
    $deferred->release();

    expect($cleaned)->toBeTrue();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'Could not send a test mail: RuntimeException: Connection refused')->once();
});

it('never logs an email address: a mail server names the recipient in its answers', function (string $message, string $expected): void {
    $deferred = holdDeferred();
    Log::spy();

    AfterResponse::run('a test mail', function () use ($message): void {
        throw new RuntimeException($message);
    });
    $deferred->release();

    Log::shouldHaveReceived('error')->withArgs(fn (string $logged): bool => $logged === 'Could not send a test mail: RuntimeException: '.$expected && ! str_contains($logged, '@'))->once();
})->with([
    'in angle brackets' => ['Expected response code "250" but got code "550", with message "550 5.1.1 <ada@example.com>: Recipient address rejected".', 'Expected response code "250" but got code "550", with message "550 5.1.1 [address] Recipient address rejected".'],
    'bare' => ['Cannot send to ada.lovelace+shop@example.co.uk now', 'Cannot send to [address] now'],
    'twice' => ['From a@b.test to c@d.test failed', 'From [address] to [address] failed'],
    'none' => ['Connection could not be established with host "smtp.example.test:587"', 'Connection could not be established with host "smtp.example.test:587"'],
]);

it('logs a failing cleanup too, without hiding the first failure', function (): void {
    $deferred = holdDeferred();
    Log::spy();

    AfterResponse::run('a test mail', function (): void {
        throw new RuntimeException('Connection refused');
    }, function (): void {
        throw new LogicException('Database is down for ada@example.com');
    });
    $deferred->release();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Could not send a test mail: RuntimeException: Connection refused'))->once();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'Could not clean up after failing to send a test mail: LogicException: Database is down for [address]')->once();
});

it('is skipped, like all deferred work, when the response is an error, and runs when it is not', function (): void {
    $ran = 0;
    $work = function () use (&$ran): void {
        $ran++;
    };
    $middleware = new InvokeDeferredCallbacks;

    AfterResponse::run('a test mail', $work);
    $middleware->terminate(Request::create('/'), new Response('', 500));

    expect($ran)->toBe(0);

    AfterResponse::run('a test mail', $work);
    $middleware->terminate(Request::create('/'), new Response('', 200));

    expect($ran)->toBe(1);
});
