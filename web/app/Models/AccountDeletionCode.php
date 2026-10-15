<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\AccountDeletionCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The code waiting for a user who asked to delete their account: the keyed hash of the code (never the code), when
 * it stops being valid and how many wrong codes were typed. One row per user: asking for a new code replaces the
 * previous one. It disappears with the user.
 *
 * Managed by {@see AccountDeletionCodes}; nothing else should write it.
 *
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon $created_at
 */
final class AccountDeletionCode extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'int';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
