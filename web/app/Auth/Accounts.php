<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creating accounts.
 *
 * Every account is created through here, so that an address is always stored
 * in canonical form and an address that was proven (by a code, or by the person
 * installing the instance) is marked verified.
 */
final class Accounts
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Creates the account of a person who proved their address.
     *
     * Two sign-ups racing for the same address end up with one account: the
     * loser receives the winner's.
     *
     * @param  string  $email  Address, in any case: it is stored in canonical form.
     * @param  string  $locale  The language the person is using, which becomes the language of the account.
     * @return array{0: User, 1: bool} The account, and whether this call created it.
     */
    public function register(string $email, string $name, string $locale): array
    {
        $email = User::normalizeEmail($email);

        try {
            $user = new User(['name' => $name, 'email' => $email, 'locale' => $locale]);
            $user->email_verified_at = now();

            // A transaction of its own (a savepoint inside another one) so that a refused insert leaves no aborted transaction behind.
            $this->db->transaction(fn (): bool => $user->save());

            return [$user, true];
        } catch (UniqueConstraintViolationException) {
            return [User::query()->where('email', $email)->firstOrFail(), false];
        }
    }
}
