<?php

declare(strict_types=1);

use App\Auth\LoginCodeOutcome;
use App\Auth\LoginCodes;
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

describe('the table', function (): void {
    it('has the columns the service relies on', function (): void {
        expect(Schema::getColumnListing('login_codes'))->toEqualCanonicalizing(['email', 'code_hash', 'expires_at', 'attempts', 'created_at']);
    });
});
