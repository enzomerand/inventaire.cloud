<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\AccountDeletion;
use App\Auth\EmailAddress;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Database\DeadlockRetries;
use App\Workspaces\Workspaces;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who can sign in to the application.
 *
 * Accounts have no password: the owner of the email address signs in with a
 * code sent to it, which also proves the address, so it is marked verified at
 * the first sign-in.
 *
 * `locale` is the language the account follows (null until it is known, the
 * application then uses the visitor's own choice). `is_admin` is deliberately
 * not mass-assignable: only the installer and `inventaire:make-admin` set it.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $locale
 * @property bool $is_admin
 * @property int|null $current_workspace_id The workspace the person is working in (null until they pick one: see {@see Workspaces::current()}).
 * @property Carbon|null $email_verified_at
 */
#[Fillable(['name', 'email', 'locale'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Defaults of a user that is not saved yet: the same as the table's, so a new model reads like a stored one.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'current_workspace_id' => 'integer',
        ];
    }

    /**
     * Stores the address in its canonical form ({@see EmailAddress::normalize()}), whatever path creates the user.
     *
     * Addresses are stored this way and looked up this way: the database then compares them exactly (the email
     * columns use a binary collation on MySQL and MariaDB, see the migration that sets it), so the same person
     * cannot own two accounts, and a lookalike address cannot reach somebody else's.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => EmailAddress::normalize($value));
    }

    /**
     * Gives every new account a "remember me" token, so that the fingerprint of its sessions
     * ({@see self::getAuthPassword()}) exists from the start, whatever creates the account.
     *
     * Also hands the deletion of an account over to {@see AccountDeletion}, whoever deletes it (see {@see self::delete()}).
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (empty($user->getRememberToken())) {
                $user->setRememberToken(Str::random(60));
            }
        });

        static::deleting(function (self $user): void {
            app(AccountDeletion::class)->release($user);
        });
    }

    /**
     * Deletes the account, and with it everything that belongs to it alone (see {@see AccountDeletion}).
     *
     * It runs in one transaction, and the `deleting` event releases what goes with the account (its sole-member
     * workspaces, one by one through their own `delete()`, its tokens, sessions and codes) before the row itself goes.
     * When the database picks that transaction as the loser of a deadlock, it is run again ({@see DeadlockRetries},
     * with a pause that differs from one request to the next) if this is the outermost transaction, which is the case
     * of the owner deleting their own account. Inside another one (an administrator's deletion of an account, which is
     * run through {@see DeadlockRetries} itself), the whole of that one is retried instead, since a deadlock rolls all
     * of it back. An account that cannot be deleted yet (the only owner of a shared workspace, the last administrator)
     * raises an `AccountDeletionRefused` and nothing is changed. An account that is already gone (a stale copy of it)
     * is a `ModelNotFoundException`, not a silent success.
     *
     * @throws AccountDeletionRefused
     * @throws ModelNotFoundException
     */
    public function delete(): ?bool
    {
        $connection = $this->getConnection();
        $delete = fn (): ?bool => $connection->transaction(fn (): ?bool => parent::delete());

        return $connection->transactionLevel() === 0 ? DeadlockRetries::run($delete) : $delete();
    }

    /**
     * Accounts have no password, but the framework still asks for a "password hash": it is what ties a session
     * and a "remember me" cookie to the credentials they were opened with. Here it is derived from the remember
     * token, which every device of the account shares, so that renewing the token ("sign out everywhere") makes
     * the framework refuse every session and every cookie that was opened before:
     *
     * - the "remember me" cookie carries the token itself, and a cookie with an old token no longer finds the user;
     * - a session stores the fingerprint when it is opened, and `AuthenticateSession` (on the web pages and, through
     *   Sanctum, on the API) signs the session out as soon as the fingerprint no longer matches.
     *
     * Signing out of one device keeps the token, so the other devices stay signed in. An account without a token
     * (which only a row inserted by hand can be) has an empty fingerprint, which the framework treats as "no check".
     *
     * Warning: this is a fingerprint, not a password hash. Anything that assumes the account has a password
     * misbehaves with it: `Auth::logout()` (and `logout()` of a guard) renews the shared token and so signs the
     * account out of every device, not just this one; `logoutOtherDevices()` and `Hash::check()` against it, or the
     * `password.confirm` middleware, can never work. Sign out of this device with `logoutCurrentDevice()`, and out
     * of the others with `SignOutEverywhereController`. An architecture test forbids the calls that go wrong.
     */
    public function getAuthPassword(): string
    {
        $token = $this->getRememberToken();

        if (! is_string($token) || $token === '') {
            return '';
        }

        return hash_hmac('sha256', 'session-fingerprint|'.$token, config()->string('app.key'));
    }

    /**
     * The workspaces the user belongs to, each with a `pivot` carrying their role in it.
     *
     * @return BelongsToMany<Workspace, $this, WorkspaceMember>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(WorkspaceMember::class)
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * The language mails and notifications to this user are written in.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
