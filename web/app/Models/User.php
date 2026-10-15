<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\EmailAddress;
use App\Workspaces\Workspaces;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
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
     * Accounts have no password, but the framework still asks for its hash: the "remember me" cookie is only
     * honoured when the user has one, as a string. A constant keeps it working; the cookie's real secret is the
     * remember token, which every device of the account shares (signing out of one device keeps it, so the
     * others stay signed in; "sign out everywhere" renews it).
     */
    public function getAuthPassword(): string
    {
        return '';
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
