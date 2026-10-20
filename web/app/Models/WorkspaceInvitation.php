<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\EmailAddress;
use App\Models\Concerns\HasUlidRouteKey;
use App\Workspaces\Invitations;
use App\Workspaces\StoredRole;
use App\Workspaces\WorkspaceRole;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to join a workspace, addressed to an email address.
 *
 * It is not a link and holds no secret: whoever signs in with the address (the code sent to it proves they own it) sees it and
 * accepts or declines it. The role it gives is an editor's or a viewer's, never an owner's (a workspace has one owner, who hands
 * the ownership over to a member). Everything that creates, sends again, accepts or deletes one goes through {@see Invitations}.
 *
 * `updated_at` is the moment the mail was last sent. An invitation past `expires_at` is as good as gone: it is
 * invisible and refused everywhere ({@see self::scopePending()}), and only cleared away later by `inventaire:prune-invitations`.
 * `ulid` is the identifier the outside world uses ({@see HasUlidRouteKey}).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property int|null $invited_by_id The account that sent it; null once that account was deleted.
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $inviter
 */
final class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory, HasUlidRouteKey;

    /**
     * The attributes that can be filled in bulk: everything but the ULID, which the model gives itself.
     *
     * @var list<string>
     */
    protected $fillable = ['workspace_id', 'email', 'role', 'invited_by_id', 'expires_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            // Not the framework's enum cast: a role this version does not know is read as a viewer instead of failing.
            'role' => StoredRole::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Stores the address in its canonical form ({@see EmailAddress::normalize()}), whatever path creates the invitation: it is
     * compared exactly with the address of an account.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => EmailAddress::normalize($value));
    }

    /**
     * The workspace the invitation gives access to.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The account that sent the invitation, when it still exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * The invitations that can still be answered: those whose time is not up. Every reader goes through this, so that an invitation
     * that expired is invisible before it is pruned.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * The invitations addressed to an address. It is compared as stored: the address is normalized here.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAddressedTo(Builder $query, string $email): Builder
    {
        return $query->where('email', EmailAddress::normalize($email));
    }

    /**
     * Whether the time of the invitation is up (it may not have been pruned yet).
     */
    public function isExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }
}
