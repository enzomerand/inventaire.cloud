<?php

declare(strict_types=1);

use App\Auth\LoginCodes;
use App\Auth\LoginFailures;
use App\Models\LoginCode;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The counter on top of a cache store that cannot count (it stores nothing).
 */
function uncountableFailures(): LoginFailures
{
    return new LoginFailures(new RateLimiter(new Repository(new NullStore)), config());
}

describe('the budget of wrong codes of an address', function (): void {
    beforeEach(function (): void {
        config(['auth.login_codes.failures_per_email' => ['attempts' => 3, 'minutes' => 5]]);
    });

    it('is spent one place at a time and refuses the guess that finds it empty', function (): void {
        $failures = app(LoginFailures::class);

        expect($failures->reserve('ada@example.com'))->toBeTrue()
            ->and($failures->reserve('ada@example.com'))->toBeTrue()
            ->and($failures->reserve('ada@example.com'))->toBeTrue()
            ->and($failures->count('ada@example.com'))->toBe(3)
            // The refused guess takes nothing: the count stays at the limit however often it is refused.
            ->and($failures->reserve('ada@example.com'))->toBeFalse()
            ->and($failures->reserve('ada@example.com'))->toBeFalse()
            ->and($failures->count('ada@example.com'))->toBe(3);
    });

    it('gets a place back, which another guess can then take', function (): void {
        $failures = app(LoginFailures::class);

        foreach (range(1, 3) as $ignored) {
            $failures->reserve('ada@example.com');
        }

        $failures->release('ada@example.com');

        expect($failures->count('ada@example.com'))->toBe(2)
            ->and($failures->reserve('ada@example.com'))->toBeTrue()
            ->and($failures->reserve('ada@example.com'))->toBeFalse();
    });

    it('is kept for each address', function (): void {
        $failures = app(LoginFailures::class);

        foreach (range(1, 4) as $ignored) {
            $failures->reserve('ada@example.com');
        }

        expect($failures->reserve('grace@example.com'))->toBeTrue()
            ->and($failures->count('grace@example.com'))->toBe(1);
    });

    it('starts its period over when every place was given back', function (): void {
        $this->freezeSecond();
        $failures = app(LoginFailures::class);

        $failures->reserve('ada@example.com');
        $this->travel(2)->minutes();
        $failures->release('ada@example.com');

        // Nothing is counted any more: the period that began with that guess is forgotten, not carried over.
        $failures->reserve('ada@example.com');

        expect($failures->count('ada@example.com'))->toBe(1)
            ->and($failures->retryAfter('ada@example.com'))->toBe(300);
    });

    it('never counts below zero', function (): void {
        $failures = app(LoginFailures::class);

        $failures->release('ada@example.com');

        expect($failures->count('ada@example.com'))->toBe(0)
            ->and($failures->reserve('ada@example.com'))->toBeTrue()
            ->and($failures->count('ada@example.com'))->toBe(1);
    });

    it('waits for the end of the period, or for a whole one when that end is not known', function (): void {
        $this->freezeSecond();
        $failures = app(LoginFailures::class);

        expect($failures->retryAfter('ada@example.com'))->toBe(300);

        $failures->reserve('ada@example.com');
        $this->travel(100)->seconds();

        expect($failures->retryAfter('ada@example.com'))->toBe(200);
    });
});

describe('a count that cannot be written', function (): void {
    it('is an error, never a guess let through', function (): void {
        expect(fn () => uncountableFailures()->reserve('ada@example.com'))
            ->toThrow(RuntimeException::class, 'could not be updated');
    });

    it('stops the judging of a code: nothing is judged, spent or counted', function (): void {
        app()->instance(LoginFailures::class, uncountableFailures());
        $code = app(LoginCodes::class)->issue('ada@example.com');

        expect(fn () => app(LoginCodes::class)->verify('ada@example.com', $code))->toThrow(RuntimeException::class);

        // The code is still there with all its attempts, and the right one is still not judged.
        $row = LoginCode::query()->findOrFail('ada@example.com');
        expect($row->attempts)->toBe(0)->and(LoginCode::query()->count())->toBe(1);
    });

    it('answers a server error and signs nobody in', function (): void {
        app()->instance(LoginFailures::class, uncountableFailures());
        $code = receiveCode($this, 'ada@example.com');

        typeCode($this, 'ada@example.com', $code, ['name' => 'Ada'])->assertStatus(500);
        typeCode($this, 'ada@example.com', wrongCode($code))->assertStatus(500);

        $this->assertGuest('web');
        expect(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0);
    });
});
