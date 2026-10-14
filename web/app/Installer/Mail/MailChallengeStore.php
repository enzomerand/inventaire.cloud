<?php

declare(strict_types=1);

namespace App\Installer\Mail;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Session\Session;

/**
 * Keeps the pending mail verification in the visitor's session.
 *
 * The challenge holds the SMTP password being tested, so it is encrypted with
 * the application key before it reaches the session store (a file at this
 * stage).
 */
final class MailChallengeStore
{
    private const KEY = 'installer.mail';

    public function __construct(private readonly Encrypter $encrypter) {}

    /**
     * Stores (or replaces) the pending challenge.
     */
    public function put(Session $session, MailChallenge $challenge): void
    {
        $session->put(self::KEY, $this->encrypter->encryptString($challenge->toPayload()));
    }

    /**
     * The pending challenge, or null when there is none or it is unreadable.
     */
    public function get(Session $session): ?MailChallenge
    {
        $stored = $session->get(self::KEY);

        if (! is_string($stored)) {
            return null;
        }

        try {
            return MailChallenge::fromPayload($this->encrypter->decryptString($stored));
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Discards the pending challenge.
     */
    public function forget(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
