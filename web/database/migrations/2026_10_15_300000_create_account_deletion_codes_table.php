<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table of pending account deletion codes: at most one per user, stored as a keyed hash.
 *
 * Kept apart from the sign-in codes (which are keyed by email address and counted against an address) so that
 * asking to delete one's account can neither replace the code a sign-in is waiting for nor spend its attempts.
 * The row goes with the user.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_deletion_codes', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_deletion_codes');
    }
};
