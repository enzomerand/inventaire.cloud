<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The verdict on a submitted sign-in code, with what the person can still try.
 */
final readonly class LoginCodeVerdict
{
    /**
     * @param  LoginCodeOutcome  $outcome  What happened to the code.
     * @param  int  $attemptsLeft  Wrong codes still tolerated for the pending code (0 once it is burnt or gone).
     * @param  int  $retryAfter  For {@see LoginCodeOutcome::Locked}: seconds before the address may try again.
     */
    public function __construct(
        public LoginCodeOutcome $outcome,
        public int $attemptsLeft = 0,
        public int $retryAfter = 0,
    ) {}

    /**
     * Whether the code was the right one.
     */
    public function accepted(): bool
    {
        return $this->outcome === LoginCodeOutcome::Accepted;
    }
}
