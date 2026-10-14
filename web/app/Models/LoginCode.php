<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\LoginCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The sign-in code waiting for an email address: the keyed hash of the code
 * (never the code), when it stops being valid and how many wrong codes were
 * typed. One row per address: asking for a new code replaces the previous one.
 *
 * Managed by {@see LoginCodes}; nothing else should write it.
 *
 * @property string $email
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon $created_at
 */
final class LoginCode extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'email';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
