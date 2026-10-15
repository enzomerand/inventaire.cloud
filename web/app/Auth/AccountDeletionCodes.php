<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\AccountDeletionCode;
use App\Models\User;
use App\Services\OneTimeCode;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;

/**
 * The codes that confirm the deletion of an account: issuing them and judging what people type back.
 *
 * Deleting an account cannot be undone, so being signed in is not enough: the person must read a code from the
 * mailbox of the account. These codes are not the sign-in codes ({@see LoginCodes}) and share nothing with them:
 * they live in a table of their own, keyed by user, and are hashed with a key derived from the application key and
 * the user, so that a code issued for one purpose can never be accepted for the other, nor for another user. Asking
 * for one therefore leaves the sign-in code of the same address alone, and wrong guesses are counted here only.
 *
 * Like a sign-in code, a code is valid for a few minutes and a few wrong guesses, works once, and is only ever
 * stored as a keyed hash ({@see OneTimeCode}). Judging happens in a transaction that holds the row's lock, so that
 * parallel requests cannot make more guesses than allowed. Sending is limited per user by the route's throttle.
 */
final class AccountDeletionCodes
{
    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Creates a fresh code for a user, replacing the one they had, and returns it to be sent. Expired codes of
     * other users are dropped on the way, which keeps the table small without a scheduled task.
     */
    public function issue(User $user): string
    {
        $code = OneTimeCode::generate();
        $now = now();

        AccountDeletionCode::query()->where('expires_at', '<', $now)->delete();

        AccountDeletionCode::query()->upsert([[
            'user_id' => $user->getKey(),
            'code_hash' => OneTimeCode::hash($code, $this->key($user)),
            'expires_at' => $now->copy()->addMinutes($this->lifetimeMinutes()),
            'attempts' => 0,
            'created_at' => $now,
        ]], ['user_id'], ['code_hash', 'expires_at', 'attempts', 'created_at']);

        return $code;
    }

    /**
     * Judges a submitted code.
     *
     * A wrong code costs an attempt; the right one is not spent here: it goes when the account does, so that a
     * deletion refused afterwards (an administrator who is the last one, a workspace to hand over first) does not
     * make the person ask for another code. A code that was never asked for, replaced, or expired is reported as
     * {@see LoginCodeOutcome::Expired}: from the outside they all mean "ask for a new one".
     */
    public function verify(User $user, string $submitted): LoginCodeVerdict
    {
        return $this->db->transaction(function () use ($user, $submitted): LoginCodeVerdict {
            $code = AccountDeletionCode::query()->whereKey($user->getKey())->lockForUpdate()->first();

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

            if (OneTimeCode::matches($submitted, $code->code_hash, $this->key($user))) {
                return new LoginCodeVerdict(LoginCodeOutcome::Accepted);
            }

            $code->attempts++;
            $code->save();

            return $code->attempts >= $max
                ? new LoginCodeVerdict(LoginCodeOutcome::TooManyAttempts)
                : new LoginCodeVerdict(LoginCodeOutcome::Wrong, $max - $code->attempts);
        });
    }

    /**
     * Drops the pending code of a user (a code that could not be sent).
     *
     * @param  string  $code  The code that could not be sent: only that one is dropped (asking again replaces the
     *                        pending code, and a mail that fails late must not take a newer one away).
     */
    public function forget(User $user, string $code): void
    {
        AccountDeletionCode::query()
            ->whereKey($user->getKey())
            ->where('code_hash', OneTimeCode::hash($code, $this->key($user)))
            ->delete();
    }

    /**
     * Minutes a code stays valid.
     */
    public function lifetimeMinutes(): int
    {
        return $this->config->integer('auth.account_deletion.lifetime_minutes');
    }

    /**
     * Wrong codes tolerated before the pending code is burnt.
     */
    public function maxAttempts(): int
    {
        return $this->config->integer('auth.account_deletion.max_attempts');
    }

    /**
     * Secret a user's deletion codes are hashed with: derived from the application key, the purpose and the user.
     */
    private function key(User $user): string
    {
        return hash_hmac('sha256', 'account-deletion|'.$user->getKey(), $this->config->string('app.key'));
    }
}
