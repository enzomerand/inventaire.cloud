<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ends the sessions that were opened before sessions carried a fingerprint of their account.
 *
 * A session is tied to its account by a fingerprint stored in it when it is opened (see
 * App\Models\User::getAuthPassword()), and "sign out everywhere" renews what the fingerprint derives from. A
 * session opened before that has no fingerprint, and what this version does with such a session is refuse it
 * (App\Auth\SessionFingerprint): this deletes them up front so that everybody signs in again once, at the update, and
 * not at some later request. It also makes sure no session that predates the update can have survived a renewal.
 *
 * Only the sessions kept in the database are listed here; sessions kept in files, the cache or redis are refused when
 * they come back, by the same rule. Safe to run again: it only empties the table, and a session opened afterwards
 * has its fingerprint.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = config('session.connection');
        $connection = is_string($connection) ? $connection : null;
        $table = config()->string('session.table');

        if (Schema::connection($connection)->hasTable($table)) {
            DB::connection($connection)->table($table)->delete();
        }
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: the sessions that were ended cannot be given back, and people just sign in again.
     */
    public function down(): void {}
};
