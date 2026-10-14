<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\LoginCode;
use App\Models\User;
use App\Services\OneTimeCode;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;

/**
 * The sign-in codes: issuing them and judging what people type back.
 *
 * Each address has at most one pending code, valid for a few minutes and for a
 * handful of wrong guesses. The code itself is never stored: only its keyed
 * hash ({@see OneTimeCode}), so a leaked database cannot be replayed. Judging a
 * code happens in a transaction that holds a lock on the address's row, so that
 * parallel requests cannot make more guesses than the limit allows.
 *
 * Addresses are compared in lowercase (see {@see User::normalizeEmail()}).
 * Nothing here looks at the users: a code is issued, and judged, in the same
 * way for an address that has an account and one that has not.
 */
final class LoginCodes
{
    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Creates a fresh code for an address, replacing the one it had, and
     * returns it to be sent. Expired codes of other addresses are dropped on
     * the way, which keeps the table small without a scheduled task.
     *
     * @param  string  $email  Address, already normalized.
     */
    public function issue(string $email): string
    {
        $code = OneTimeCode::generate();
        $now = now();

        LoginCode::query()->where('expires_at', '<', $now)->delete();

        LoginCode::query()->upsert([[
            'email' => $email,
            'code_hash' => OneTimeCode::hash($code, $this->key()),
            'expires_at' => $now->copy()->addMinutes($this->lifetimeMinutes()),
            'attempts' => 0,
            'created_at' => $now,
        ]], ['email'], ['code_hash', 'expires_at', 'attempts', 'created_at']);

        return $code;
    }

    /**
     * Judges a submitted code.
     *
     * A wrong code costs an attempt; the right one is spent (the code cannot
     * be used twice) unless $consume is false, for a caller that needs one
     * more input before it can act on it.
     *
     * @param  string  $email  Address, already normalized.
     * @param  bool  $consume  Whether an accepted code is used up.
     */
    public function verify(string $email, string $submitted, bool $consume = true): LoginCodeVerdict
    {
        return $this->db->transaction(function () use ($email, $submitted, $consume): LoginCodeVerdict {
            $code = LoginCode::query()->whereKey($email)->lockForUpdate()->first();

            if ($code === null) {
                return new LoginCodeVerdict(LoginCodeOutcome::Expired);
            }

            if (now()->greaterThan($code->expires_at)) {
                $code->delete();

                return new LoginCodeVerdict(LoginCodeOutcome::Expired);
            }

            $max = $this->maxAttempts();

            if ($code->attempts >= $max) {
                return new LoginCodeVerdict(LoginCodeOutcome::TooManyAttempts);
            }

            if (OneTimeCode::matches($submitted, $code->code_hash, $this->key())) {
                if ($consume) {
                    $code->delete();
                }

                return new LoginCodeVerdict(LoginCodeOutcome::Accepted, $max - $code->attempts);
            }

            $code->attempts++;
            $code->save();

            return $code->attempts >= $max
                ? new LoginCodeVerdict(LoginCodeOutcome::TooManyAttempts)
                : new LoginCodeVerdict(LoginCodeOutcome::Wrong, $max - $code->attempts);
        });
    }

    /**
     * Drops the pending code of an address (a code that could not be sent).
     */
    public function forget(string $email): void
    {
        LoginCode::query()->whereKey($email)->delete();
    }

    /**
     * Minutes a code stays valid.
     */
    public function lifetimeMinutes(): int
    {
        return $this->config->integer('auth.login_codes.lifetime_minutes');
    }

    /**
     * Wrong codes tolerated before the pending code is burnt.
     */
    public function maxAttempts(): int
    {
        return $this->config->integer('auth.login_codes.max_attempts');
    }

    /**
     * Secret the codes are hashed with: the application key.
     */
    private function key(): string
    {
        return $this->config->string('app.key');
    }
}
