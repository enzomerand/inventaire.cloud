<?php

declare(strict_types=1);

use App\Auth\LoginCodeOutcome;
use App\Auth\LoginCodes;
use App\Auth\LoginFailures;
use App\Models\LoginCode;
use App\Services\OneTimeCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The service under test.
 */
function codes(): LoginCodes
{
    return app(LoginCodes::class);
}

describe('issuing a code', function (): void {
    it('stores a keyed hash of the code, never the code', function (): void {
        $code = codes()->issue('ada@example.com');
        $row = LoginCode::query()->findOrFail('ada@example.com');

        expect($code)->toMatch('/^\d{6}$/')
            ->and($row->code_hash)->toBe(hash_hmac('sha256', $code, config()->string('app.key')))
            ->and($row->code_hash)->not->toContain($code)
            ->and(json_encode(LoginCode::query()->get()->toArray()))->not->toContain('"'.$code.'"');
    });

    it('is valid for ten minutes', function (): void {
        // Timestamps are stored to the second: freeze on a whole one to compare exactly.
        $this->freezeSecond();

        codes()->issue('ada@example.com');

        expect(LoginCode::query()->findOrFail('ada@example.com')->expires_at->equalTo(now()->addMinutes(10)))->toBeTrue()
            ->and(codes()->lifetimeMinutes())->toBe(10);
    });

    it('replaces the previous code of the address', function (): void {
        $first = codes()->issue('ada@example.com');
        $second = codes()->issue('ada@example.com');

        expect(LoginCode::query()->count())->toBe(1);

        // The first code is gone for good, unless chance gave the same digits twice.
        if ($first !== $second) {
            expect(codes()->verify('ada@example.com', $first)->outcome)->toBe(LoginCodeOutcome::Wrong);
        }

        expect(codes()->verify('ada@example.com', $second)->outcome)->toBe(LoginCodeOutcome::Accepted);
    });

    it('gives a new code a fresh set of attempts', function (): void {
        $code = codes()->issue('ada@example.com');
        codes()->verify('ada@example.com', '000000');
        codes()->verify('ada@example.com', '000000');

        codes()->issue('ada@example.com');

        expect(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0)
            ->and($code)->toMatch('/^\d{6}$/');
    });

    it('keeps one code per address', function (): void {
        codes()->issue('ada@example.com');
        codes()->issue('grace@example.com');

        expect(LoginCode::query()->count())->toBe(2);
    });

    it('drops the expired codes of other addresses on the way', function (): void {
        codes()->issue('old@example.com');
        $this->travel(11)->minutes();

        codes()->issue('new@example.com');

        expect(LoginCode::query()->pluck('email')->all())->toBe(['new@example.com']);
    });
});

describe('verifying a code', function (): void {
    it('accepts the right code once', function (): void {
        $code = codes()->issue('ada@example.com');

        expect(codes()->verify('ada@example.com', $code)->accepted())->toBeTrue()
            ->and(LoginCode::query()->count())->toBe(0)
            ->and(codes()->verify('ada@example.com', $code)->outcome)->toBe(LoginCodeOutcome::Expired);
    });

    it('keeps an accepted code when asked not to consume it', function (): void {
        $code = codes()->issue('ada@example.com');

        expect(codes()->verify('ada@example.com', $code, consume: false)->accepted())->toBeTrue()
            ->and(LoginCode::query()->count())->toBe(1)
            ->and(codes()->verify('ada@example.com', $code)->accepted())->toBeTrue();
    });

    it('tolerates whitespace around the code', function (): void {
        $code = codes()->issue('ada@example.com');

        expect(codes()->verify('ada@example.com', " {$code}\n")->accepted())->toBeTrue();
    });

    it('counts wrong codes and burns the code at the fifth', function (): void {
        $code = codes()->issue('ada@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach ([4, 3, 2, 1] as $left) {
            $verdict = codes()->verify('ada@example.com', $wrong);

            expect($verdict->outcome)->toBe(LoginCodeOutcome::Wrong)->and($verdict->attemptsLeft)->toBe($left);
        }

        expect(codes()->verify('ada@example.com', $wrong)->outcome)->toBe(LoginCodeOutcome::TooManyAttempts)
            ->and(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(5);

        // Even the right code is refused afterwards.
        expect(codes()->verify('ada@example.com', $code)->outcome)->toBe(LoginCodeOutcome::TooManyAttempts);
    });

    it('does not count the right code as an attempt', function (): void {
        $code = codes()->issue('ada@example.com');

        codes()->verify('ada@example.com', $code, consume: false);

        expect(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0);
    });

    it('refuses a code after ten minutes, and not before', function (): void {
        $this->freezeSecond();
        $code = codes()->issue('ada@example.com');

        $this->travel(10)->minutes();
        expect(codes()->verify('ada@example.com', $code, consume: false)->accepted())->toBeTrue();

        $this->travel(1)->seconds();
        expect(codes()->verify('ada@example.com', $code)->outcome)->toBe(LoginCodeOutcome::Expired)
            ->and(LoginCode::query()->count())->toBe(0);
    });

    it('reports an address without a code like an expired one', function (): void {
        expect(codes()->verify('nobody@example.com', '123456')->outcome)->toBe(LoginCodeOutcome::Expired);
    });

    it('does not accept a code hashed with another key', function (): void {
        codes()->issue('ada@example.com');
        LoginCode::query()->update(['code_hash' => OneTimeCode::hash('123456', 'another-key')]);

        expect(codes()->verify('ada@example.com', '123456')->outcome)->toBe(LoginCodeOutcome::Wrong);
    });

    it('keeps the codes of two addresses apart', function (): void {
        $ada = codes()->issue('ada@example.com');
        $grace = codes()->issue('grace@example.com');

        // Ada's code is useless for Grace's address, unless chance gave them the same digits.
        if ($ada !== $grace) {
            expect(codes()->verify('grace@example.com', $ada)->outcome)->toBe(LoginCodeOutcome::Wrong);
        }

        expect(codes()->verify('ada@example.com', $ada)->accepted())->toBeTrue();
    });

    it('forgets the code of an address', function (): void {
        $code = codes()->issue('ada@example.com');

        codes()->forget('ada@example.com');

        expect(codes()->verify('ada@example.com', $code)->outcome)->toBe(LoginCodeOutcome::Expired);
    });
});

describe('wrong codes over an hour', function (): void {
    /**
     * Types five wrong codes against a freshly issued code for the address, and returns that code.
     */
    function fiveWrongCodes(string $email): string
    {
        $code = codes()->issue($email);

        foreach (range(1, 5) as $ignored) {
            codes()->verify($email, wrongCode($code));
        }

        return $code;
    }

    it('lock an address that collected ten of them, whichever codes they were typed against', function (): void {
        $this->freezeSecond();

        fiveWrongCodes('ada@example.com');
        fiveWrongCodes('ada@example.com');

        // A new code gives its five guesses back, but not the ten wrong codes already counted.
        $fresh = codes()->issue('ada@example.com');
        $verdict = codes()->verify('ada@example.com', $fresh);

        expect($verdict->outcome)->toBe(LoginCodeOutcome::Locked)
            ->and($verdict->accepted())->toBeFalse()
            ->and($verdict->retryAfter)->toBe(3600)
            // Nothing was spent: the code is still there, with all its attempts.
            ->and(LoginCode::query()->findOrFail('ada@example.com')->attempts)->toBe(0);
    });

    it('lock the address for the right code as well as for a wrong one', function (): void {
        fiveWrongCodes('ada@example.com');
        fiveWrongCodes('ada@example.com');
        $code = codes()->issue('ada@example.com');

        expect(codes()->verify('ada@example.com', wrongCode($code))->outcome)->toBe(LoginCodeOutcome::Locked)
            ->and(codes()->verify('ada@example.com', $code)->outcome)->toBe(LoginCodeOutcome::Locked);
    });

    it('are forgotten once the period that opened with the first of them is over', function (): void {
        $this->freezeSecond();

        fiveWrongCodes('ada@example.com');
        $this->travel(30)->minutes();
        fiveWrongCodes('ada@example.com');

        // Ten in the period: locked until it is over, that is for 30 more minutes (the later failures do not push it back).
        $code = codes()->issue('ada@example.com');
        expect(codes()->verify('ada@example.com', $code, consume: false)->retryAfter)->toBe(1800);

        // A second before the hour is up (the code asked for meanwhile is a new one: they only last ten minutes).
        $this->travel(1799)->seconds();
        $code = codes()->issue('ada@example.com');
        expect(codes()->verify('ada@example.com', $code, consume: false))->outcome->toBe(LoginCodeOutcome::Locked)->retryAfter->toBe(1);

        $this->travel(1)->seconds();
        $code = codes()->issue('ada@example.com');
        expect(codes()->verify('ada@example.com', $code)->accepted())->toBeTrue()
            ->and(app(LoginFailures::class)->count('ada@example.com'))->toBe(0);
    });

    it('start a new period with the first wrong code after the previous one', function (): void {
        $this->freezeSecond();

        $code = codes()->issue('ada@example.com');
        codes()->verify('ada@example.com', wrongCode($code));

        $this->travel(61)->minutes();

        // The first one is forgotten, and the period that opens now is a whole hour.
        $code = codes()->issue('ada@example.com');
        codes()->verify('ada@example.com', wrongCode($code));

        expect(app(LoginFailures::class)->count('ada@example.com'))->toBe(1)
            ->and(app(LoginFailures::class)->retryAfter('ada@example.com'))->toBe(3600);
    });

    it('do not count what is typed again at a code that is already burnt', function (): void {
        $code = fiveWrongCodes('ada@example.com');

        // Whoever retypes a burnt code (the right one included) is told so, over and over, and it costs the address nothing.
        foreach (range(1, 12) as $ignored) {
            expect(codes()->verify('ada@example.com', wrongCode($code))->outcome)->toBe(LoginCodeOutcome::TooManyAttempts)
                ->and(codes()->verify('ada@example.com', $code)->outcome)->toBe(LoginCodeOutcome::TooManyAttempts);
        }

        expect(app(LoginFailures::class)->count('ada@example.com'))->toBe(5);

        // Five more wrong codes at a new code make ten: only now is the address locked.
        fiveWrongCodes('ada@example.com');

        expect(app(LoginFailures::class)->count('ada@example.com'))->toBe(10)
            ->and(codes()->verify('ada@example.com', codes()->issue('ada@example.com'))->outcome)->toBe(LoginCodeOutcome::Locked);
    });

    it('do not count a code that was spent already, typed again', function (): void {
        $code = codes()->issue('ada@example.com');
        codes()->verify('ada@example.com', $code);

        foreach (range(1, 12) as $ignored) {
            expect(codes()->verify('ada@example.com', $code)->outcome)->toBe(LoginCodeOutcome::Expired);
        }

        expect(app(LoginFailures::class)->count('ada@example.com'))->toBe(0);

        $fresh = codes()->issue('ada@example.com');
        expect(codes()->verify('ada@example.com', $fresh)->accepted())->toBeTrue();
    });

    it('do not count a right code, nor codes typed for an address that has none', function (): void {
        foreach (range(1, 12) as $ignored) {
            expect(codes()->verify('nobody@example.com', '123456')->outcome)->toBe(LoginCodeOutcome::Expired);
        }

        foreach (range(1, 12) as $ignored) {
            $code = codes()->issue('ada@example.com');
            expect(codes()->verify('ada@example.com', $code)->accepted())->toBeTrue();
        }

        $code = codes()->issue('nobody@example.com');
        expect(codes()->verify('nobody@example.com', $code)->accepted())->toBeTrue()
            ->and(app(LoginFailures::class)->count('ada@example.com'))->toBe(0)
            ->and(app(LoginFailures::class)->count('nobody@example.com'))->toBe(0);
    });

    it('are counted for each address on its own', function (): void {
        fiveWrongCodes('ada@example.com');
        fiveWrongCodes('ada@example.com');

        $code = codes()->issue('grace@example.com');

        expect(codes()->verify('grace@example.com', $code)->accepted())->toBeTrue();
    });

    it('follow the limit in the configuration', function (): void {
        config(['auth.login_codes.failures_per_email' => ['attempts' => 3, 'minutes' => 5]]);
        $this->freezeSecond();

        $code = codes()->issue('ada@example.com');

        foreach (range(1, 3) as $ignored) {
            codes()->verify('ada@example.com', wrongCode($code));
        }

        expect(codes()->verify('ada@example.com', $code))->outcome->toBe(LoginCodeOutcome::Locked)->retryAfter->toBe(300);
    });
});

describe('the table', function (): void {
    it('has the columns the service relies on', function (): void {
        expect(Schema::getColumnListing('login_codes'))->toEqualCanonicalizing(['email', 'code_hash', 'expires_at', 'attempts', 'created_at']);
    });
});
