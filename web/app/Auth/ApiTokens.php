<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Exceptions\ApiTokenLimitReachedException;
use App\Database\DeadlockRetries;
use App\Models\User;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Making, listing and revoking the API tokens of a person.
 *
 * A token is a Sanctum personal access token: what is stored is the hash of its value (which nobody can read back, so it is
 * given once, when the token is made), its name, the abilities it was given ({@see TokenAbility}) and the moment it stops working,
 * if it does. A person may have `inventories.max_api_tokens` tokens that did not expire; the count is made under the lock of the
 * person's row, so that requests made at the same moment cannot cross it together. Expired tokens do not count, are not listed
 * and are deleted a day later by `sanctum:prune-expired`.
 */
final class ApiTokens
{
    /** Days a token lives when the request does not say. */
    public const DEFAULT_LIFETIME_DAYS = 90;

    /**
     * Days a token may be made to live; a token may also live for ever (null).
     *
     * @var list<int>
     */
    public const LIFETIMES = [7, 30, 90, 365];

    /**
     * Gets the database and the locks.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
    ) {}

    /**
     * The tokens of a person that did not expire, the newest first.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function active(User $user): Collection
    {
        return $this->activeOf($user)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * Makes a token for a person. Its value is in the result and nowhere else: it is not kept.
     *
     * Retried when the database picks it as the loser of a deadlock ({@see DeadlockRetries}).
     *
     * @param  string  $name  Already validated and trimmed.
     * @param  list<string>  $abilities  Values of {@see TokenAbility}, at least one; they are stored once each, in the order of the enum.
     * @param  int|null  $lifetimeDays  Days the token works for, or null for ever.
     *
     * @throws ApiTokenLimitReachedException When the person already has as many tokens as they may.
     * @throws InvalidArgumentException When no ability is given, or one that does not exist.
     */
    public function create(User $user, string $name, array $abilities, ?int $lifetimeDays): NewAccessToken
    {
        $abilities = array_values(array_intersect(TokenAbility::values(), $abilities));

        if ($abilities === []) {
            throw new InvalidArgumentException('A token needs at least one ability.');
        }

        $limit = config()->integer('inventories.max_api_tokens');

        return DeadlockRetries::run(fn (): NewAccessToken => $this->db->transaction(function () use ($user, $name, $abilities, $lifetimeDays, $limit): NewAccessToken {
            // The row of the person serialises their requests, and an account deleted meanwhile fails here, not on a key.
            if ($this->locks->users([$user->getKey()])->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
            }

            if ($this->activeOf($user)->count() >= $limit) {
                throw new ApiTokenLimitReachedException($limit);
            }

            return $user->createToken($name, $abilities, $lifetimeDays === null ? null : now()->addDays($lifetimeDays));
        }));
    }

    /**
     * Revokes a token of a person: it stops working at once. Another person's token is not touched, and is not there for them.
     *
     * @return bool Whether the person had such a token.
     */
    public function revoke(User $user, int|string $id): bool
    {
        return $user->tokens()->whereKey($id)->delete() > 0;
    }

    /**
     * The tokens of a person that did not expire.
     *
     * @return Builder<PersonalAccessToken>
     */
    private function activeOf(User $user): Builder
    {
        /** @var Builder<PersonalAccessToken> $tokens */
        $tokens = $user->tokens()->getQuery();

        return $tokens->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
