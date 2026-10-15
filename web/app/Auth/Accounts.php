<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\DeadlockRetries;
use App\Models\User;
use App\Workspaces\Workspaces;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creating accounts.
 *
 * Every account is created through here, so that an address is always stored
 * in canonical form (see {@see EmailAddress::normalize()}) and that two requests
 * racing for the same address end up with one account. An address is marked
 * verified when its owner first signs in, with a code sent to it.
 *
 * Every account has at least one workspace: a personal one is created with the
 * account, in the same transaction, so that no path (sign-up, installation,
 * `inventaire:make-admin`) can leave an account without one.
 */
final class Accounts
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Workspaces $workspaces,
    ) {}

    /**
     * Creates an account.
     *
     * The loser of a race for the same address receives the winner's account.
     *
     * @param  string  $email  Address, in any case: it is stored in canonical form.
     * @param  string|null  $locale  The language the person is using, which becomes the language of the account (null until it is known).
     * @param  bool  $administrator  Whether the account administers the instance (only applied to an account created by this call).
     * @return array{0: User, 1: bool} The account, and whether this call created it.
     */
    public function register(string $email, string $name, ?string $locale, bool $administrator = false): array
    {
        $email = EmailAddress::normalize($email);

        try {
            $user = new User(['name' => $name, 'email' => $email, 'locale' => $locale]);
            $user->is_admin = $administrator;

            // A transaction of its own (a savepoint inside another one) so that a refused insert leaves no aborted transaction behind.
            $this->db->transaction(function () use ($user): void {
                $user->save();
                $this->workspaces->createPersonal($user);
            });

            return [$user, true];
        } catch (UniqueConstraintViolationException) {
            // A locking read sees the winner's committed row even from inside a REPEATABLE READ transaction (MySQL, MariaDB).
            return [User::query()->where('email', $email)->lockForUpdate()->firstOrFail(), false];
        }
    }

    /**
     * Makes an address the account of an administrator: creates the account,
     * or promotes the one that exists. Doing it again changes nothing, which
     * is what the installation (that can be run again) and recovery need.
     *
     * An existing account keeps its name: the name only matters for a new one. An existing account that has no
     * workspace (it predates them) is given its personal one.
     *
     * It is retried as a whole when the database picks it as the loser of a deadlock, as two accounts getting their
     * first workspace at the same moment can: the transactions it opens enclose those of {@see Workspaces}, whose own
     * retries do not apply inside another transaction (a deadlock rolls the whole of it back). Call it outside any
     * transaction, as the installer and the command line do: inside one, that transaction is the one to retry.
     *
     * @param  string  $email  Address, in any case.
     * @param  string  $name  Name of the account, used when it has to be created.
     * @return array{0: User, 1: AdministratorChange}
     */
    public function administrator(string $email, string $name): array
    {
        $email = EmailAddress::normalize($email);

        return DeadlockRetries::run(fn (): array => $this->makeAdministrator($email, $name));
    }

    /**
     * One attempt at {@see self::administrator()}.
     *
     * @param  string  $email  Address, in canonical form.
     * @return array{0: User, 1: AdministratorChange}
     */
    private function makeAdministrator(string $email, string $name): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            [$user, $created] = $this->register($email, $name, null, administrator: true);

            if ($created) {
                return [$user, AdministratorChange::Created];
            }
        }

        if ($user->is_admin) {
            $this->workspaces->ensure($user);

            return [$user, AdministratorChange::Unchanged];
        }

        $this->db->transaction(function () use ($user): void {
            $user->is_admin = true;
            $user->save();
            $this->workspaces->ensure($user);
        });

        return [$user, AdministratorChange::Promoted];
    }
}
