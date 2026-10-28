<?php

declare(strict_types=1);

namespace App\Inventories\Sharing;

use App\Billing\WorkspaceLimits;
use App\Database\DeadlockRetries;
use App\Inventories\Exceptions\ShareLinkLimitReachedException;
use App\Inventories\Exceptions\ShareLinksDisabledException;
use App\Models\Inventory;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Settings\InstanceSettings;
use App\Support\PublicUrls;
use App\Workspaces\Locks;
use App\Workspaces\WorkspaceRole;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Making, listing, revoking and resolving the share links of an inventory: secret addresses that show it, read-only, to anybody who
 * holds them.
 *
 * A token is 32 random bytes written as base64url (43 characters, {@see self::newToken()}) and is the secret of the address
 * (`/s/{token}`, {@see self::PAGE_PATH}). It is never stored as it is: the row holds its SHA-256, the only way a visitor's link is looked
 * up ({@see self::resolve()}), and the token encrypted with the application key, so that the owner can copy the address again
 * ({@see self::urlOf()}). When the key was changed and the token cannot be read back, the address is null and the owner makes a new link.
 *
 * An inventory may have `inventories.max_share_links` links (or the share links of the plan of its workspace when billing is on: {@see WorkspaceLimits}) that still work: the ones that expired do not count, they only wait to be
 * deleted. The count is made under the lock of the inventory's row, so that requests made at the same moment cannot cross it together. A link past its expiry is
 * refused by {@see self::resolve()}, as are all the links while the administrator has sharing turned off
 * ({@see InstanceSettings::shareLinksEnabled()}); unknown, malformed, expired and disabled all answer null, so a visitor cannot tell
 * them apart. Expired links stay listed to their owner, then `inventaire:prune-share-links` deletes them ({@see self::prune()}).
 *
 * Who may share is the business of the policy of the inventory (`share`: its owners); this class only enforces the limits. Public
 * addresses are built from `APP_URL` ({@see PublicUrls}), never from the request.
 */
final class ShareLinks
{
    /**
     * The path of the public page of a link, `{token}` standing for its token. The route of the page and the address given to the owner
     * come from this one string.
     */
    public const PAGE_PATH = '/s/{token}';

    /** What a token looks like, as a regular expression without delimiters or anchors: 43 characters of the base64url alphabet. */
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    /** Random bytes in a token (256 bits). */
    public const TOKEN_BYTES = 32;

    /** The longest label of a link, in characters. */
    public const LABEL_MAX_LENGTH = 80;

    /**
     * Days a link may be made to live; a link may also live for ever (null).
     *
     * @var list<int>
     */
    public const LIFETIMES = [7, 30, 90, 365];

    /** Days a link lives when the request does not say: a public address that never stops working is a choice to make on purpose. */
    public const DEFAULT_LIFETIME_DAYS = 30;

    /** Minutes between two writes of `last_used_at`, however often the link is opened. */
    public const USE_INTERVAL_MINUTES = 60;

    /** Days after its expiry that a link is kept, listed to its owner as expired, before it is deleted. */
    public const PRUNE_AFTER_DAYS = 30;

    /**
     * Gets the database, the locks, the encrypter of the application key and the settings of the instance.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly Encrypter $encrypter,
        private readonly InstanceSettings $settings,
    ) {}

    /**
     * A new token: 32 random bytes in base64url, without padding.
     */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * Whether a text has the shape of a token (nothing is looked up). A visitor's text is judged here before it reaches the database.
     */
    public static function isWellFormed(string $token): bool
    {
        return preg_match('/\A'.self::TOKEN_PATTERN.'\z/', $token) === 1;
    }

    /**
     * What the database keeps of a token: its SHA-256, in lowercase hexadecimal.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The absolute address of the public page of the link with this token, built from `APP_URL`.
     */
    public static function addressOf(string $token): string
    {
        return PublicUrls::to(str_replace('{token}', $token, self::PAGE_PATH));
    }

    /**
     * The columns that keep a token: its hash and the token encrypted with the application key.
     *
     * @return array{token_hash: string, token_encrypted: string}
     */
    public function secretsOf(string $token): array
    {
        return ['token_hash' => self::hash($token), 'token_encrypted' => $this->encrypter->encryptString($token)];
    }

    /**
     * Makes a link to an inventory. The token is in the result and, encrypted, in the row; nothing else keeps it.
     *
     * Retried when the database picks it as the loser of a deadlock ({@see DeadlockRetries}). The account of the author is locked before
     * the inventory, the order of {@see Locks}.
     *
     * @param  string|null  $label  What the owner calls the link, already trimmed and at most {@see self::LABEL_MAX_LENGTH} characters; none when null.
     * @param  bool  $includeFiles  Whether the files and photos of the items are shown and served through the link.
     * @param  int|null  $expiresInDays  One of {@see self::LIFETIMES}, or null for a link that never expires. The label and the lifetime are
     *                                   validated by the request, not here.
     * @return array{link: ShareLink, token: string}
     *
     * @throws ShareLinksDisabledException When the administrator turned sharing off.
     * @throws ShareLinkLimitReachedException When the inventory already has as many links as it may.
     * @throws ModelNotFoundException When the inventory or the account was deleted in the meantime.
     */
    public function create(User $author, Inventory $inventory, ?string $label, bool $includeFiles, ?int $expiresInDays): array
    {
        if (! $this->settings->shareLinksEnabled()) {
            throw new ShareLinksDisabledException;
        }

        return DeadlockRetries::run(fn (): array => $this->db->transaction(function () use ($author, $inventory, $label, $includeFiles, $expiresInDays): array {
            // The link names its author (a foreign key, which locks that row when the link is saved): the account comes first.
            $this->locks->author($author);
            $locked = $this->locks->inventory($inventory->getKey());
            $limit = WorkspaceLimits::shareLinksPerInventory($locked->workspace_id);

            if (ShareLink::query()->where('inventory_id', $locked->getKey())->active()->count() >= $limit) {
                throw new ShareLinkLimitReachedException($limit);
            }

            $token = self::newToken();
            $link = ShareLink::query()->create([
                'inventory_id' => $locked->getKey(),
                'created_by_id' => $author->getKey(),
                'label' => $label,
                'include_files' => $includeFiles,
                'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
            ] + $this->secretsOf($token));
            $link->setRelation('creator', $author);

            return ['link' => $link, 'token' => $token];
        }));
    }

    /**
     * The links of an inventory, the newest first, expired ones included, each with its author.
     *
     * @return Collection<int, ShareLink>
     */
    public function list(Inventory $inventory): Collection
    {
        return ShareLink::query()->where('inventory_id', $inventory->getKey())->with('creator')->orderByDesc('id')->get();
    }

    /**
     * The keys of the accounts that are owners of the workspace of the inventory now, to tell the links made by one of them from those made
     * by somebody who has left or is no longer an owner (a link outlives the ownership of its author).
     *
     * @return list<int>
     */
    public function ownerIds(Inventory $inventory): array
    {
        return array_values(WorkspaceMember::query()
            ->where('workspace_id', $inventory->workspace_id)
            ->where('role', WorkspaceRole::Owner->value)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Revokes a link of an inventory: it is deleted, and its address stops working at once. A link of another inventory is not touched.
     *
     * @return bool Whether the inventory had such a link.
     */
    public function revoke(Inventory $inventory, ShareLink $link): bool
    {
        return ShareLink::query()->where('inventory_id', $inventory->getKey())->whereKey($link->getKey())->delete() > 0;
    }

    /**
     * The address of a link, read back from the encrypted token. Null when it cannot be: the ciphertext does not decrypt (the application
     * key was changed) or what it holds is not the token of this link.
     */
    public function urlOf(ShareLink $link): ?string
    {
        try {
            $token = $this->encrypter->decryptString($link->token_encrypted);
        } catch (DecryptException) {
            return null;
        }

        // A ciphertext that decrypts but was copied from another row is no address for this one.
        if (! self::isWellFormed($token) || ! hash_equals($link->token_hash, self::hash($token))) {
            return null;
        }

        return self::addressOf($token);
    }

    /**
     * The link a visitor's token opens, with its inventory loaded, or null for every reason there is no such link: the text is not shaped
     * like a token, nothing has its hash, the link expired or sharing is turned off on the instance. The caller answers all of them
     * alike (the same 404), so that nothing tells a visitor which of them it was.
     *
     * Whether the files are shown is `$link->include_files`; the caller checks it again for each file it serves.
     */
    public function resolve(string $token): ?ShareLink
    {
        if (! self::isWellFormed($token) || ! $this->settings->shareLinksEnabled()) {
            return null;
        }

        return ShareLink::query()->active()->where('token_hash', self::hash($token))->with('inventory')->first();
    }

    /**
     * Notes that a link was used: `last_used_at` is moved to now at most once an hour, by one conditional statement (a link opened a
     * hundred times in a minute is written once, and requests made together do not wait for each other).
     *
     * @return bool Whether this call wrote it.
     */
    public function touch(ShareLink $link): bool
    {
        $now = now();

        return ShareLink::query()
            ->whereKey($link->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $now->copy()->subMinutes(self::USE_INTERVAL_MINUTES)))
            // Not through the model's builder, which would move `updated_at` too: the link did not change.
            ->toBase()
            ->update(['last_used_at' => $now]) > 0;
    }

    /**
     * Deletes the links that expired more than {@see self::PRUNE_AFTER_DAYS} days ago. Safe to call at any time and as often as wanted.
     *
     * The rows are found without a lock and deleted by their keys, a few hundred at a time: a delete that filtered on the date would
     * lock every row it passes on MySQL and MariaDB (and the gaps between them, where links are inserted).
     *
     * @return int How many were deleted.
     */
    public function prune(): int
    {
        $deleted = 0;
        $chunk = 500;
        $before = now()->subDays(self::PRUNE_AFTER_DAYS);

        do {
            $ids = ShareLink::query()->where('expires_at', '<', $before)->orderBy('id')->limit($chunk)->pluck('id');

            $deleted += $ids->isEmpty() ? 0 : ShareLink::query()->whereIn('id', $ids)->where('expires_at', '<', $before)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
