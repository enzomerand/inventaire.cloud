<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        ];
    }

    /**
     * The language mails and notifications to this user are written in.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
