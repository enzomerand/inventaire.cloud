<?php

declare(strict_types=1);

namespace App\Installer\Mail;

/**
 * A pending email verification: the values being tested and the code that was
 * sent for them.
 *
 * It is immutable and knows nothing about where it is kept: the web wizard
 * stores it encrypted in the session between the two requests, the console
 * installer simply holds it in a variable. The code itself is never stored,
 * only a keyed hash, so a leaked session cannot reveal it.
 */
final readonly class MailChallenge
{
    /**
     * @param  array<string, mixed>  $values  The mail step's values (password included) to persist once the code is confirmed.
     * @param  string  $recipient  Address the code was sent to.
     * @param  string  $codeHash  Keyed hash of the code.
     * @param  int  $expiresAt  Unix time after which the code is no longer accepted.
     * @param  int  $attempts  Wrong codes entered so far.
     */
    public function __construct(
        public array $values,
        public string $recipient,
        public string $codeHash,
        public int $expiresAt,
        public int $attempts = 0,
    ) {}

    /**
     * Starts a challenge for a freshly generated code.
     *
     * @param  array<string, mixed>  $values
     * @param  string  $key  Secret used to hash the code (the application key).
     */
    public static function issue(array $values, string $recipient, string $code, string $key, int $lifetimeMinutes, ?int $now = null): self
    {
        return new self($values, $recipient, self::hash($code, $key), ($now ?? time()) + $lifetimeMinutes * 60);
    }

    /**
     * Checks a submitted code.
     *
     * @param  int  $maxAttempts  Wrong codes tolerated before the challenge is burnt.
     * @return array{0: MailCodeOutcome, 1: self} The verdict and the challenge to keep (attempts updated).
     */
    public function verify(string $submitted, string $key, int $maxAttempts, ?int $now = null): array
    {
        if (($now ?? time()) > $this->expiresAt) {
            return [MailCodeOutcome::Expired, $this];
        }

        if ($this->attempts >= $maxAttempts) {
            return [MailCodeOutcome::TooManyAttempts, $this];
        }

        if (hash_equals($this->codeHash, self::hash(trim($submitted), $key))) {
            return [MailCodeOutcome::Accepted, $this];
        }

        $next = new self($this->values, $this->recipient, $this->codeHash, $this->expiresAt, $this->attempts + 1);

        return [$next->attempts >= $maxAttempts ? MailCodeOutcome::TooManyAttempts : MailCodeOutcome::Wrong, $next];
    }

    /**
     * Wrong codes still allowed.
     */
    public function attemptsLeft(int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts);
    }

    /**
     * Serializes the challenge (to be encrypted by the caller).
     */
    public function toPayload(): string
    {
        return json_encode([
            'values' => $this->values,
            'recipient' => $this->recipient,
            'code_hash' => $this->codeHash,
            'expires_at' => $this->expiresAt,
            'attempts' => $this->attempts,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Rebuilds a challenge from {@see self::toPayload()}, or null when the payload is unusable.
     */
    public static function fromPayload(string $payload): ?self
    {
        $data = json_decode($payload, true);

        if (! is_array($data) || ! is_array($data['values'] ?? null) || ! is_string($data['recipient'] ?? null)
            || ! is_string($data['code_hash'] ?? null) || ! is_int($data['expires_at'] ?? null) || ! is_int($data['attempts'] ?? null)) {
            return null;
        }

        return new self($data['values'], $data['recipient'], $data['code_hash'], $data['expires_at'], $data['attempts']);
    }

    private static function hash(string $code, string $key): string
    {
        return hash_hmac('sha256', $code, $key);
    }
}
