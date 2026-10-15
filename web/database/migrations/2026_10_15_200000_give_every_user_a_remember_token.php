<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a remember token to every account that has none.
 *
 * The token is what "sign out everywhere" renews, and what the fingerprint of the sessions of an account derives
 * from (see App\Models\User::getAuthPassword()): an account without one could not be signed out of its other
 * devices. Accounts get one when they first sign in, and when they are created; this brings the others in line.
 * Idempotent: it only touches accounts that have none.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->where(fn ($query) => $query->whereNull('remember_token')->orWhere('remember_token', ''))
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update(['remember_token' => Str::random(60)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: a token is harmless where it is not used.
     */
    public function down(): void {}
};
