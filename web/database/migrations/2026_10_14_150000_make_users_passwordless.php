<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the users into passwordless accounts: signing in is done with a code sent by email.
 *
 * The first users migration is already applied on installed instances and is never edited, hence this one: it drops
 * the password and its reset tokens, and adds the language the account follows and the administrator flag.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable();
            $table->boolean('is_admin')->default(false);
        });

        Schema::dropIfExists('password_reset_tokens');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'is_admin']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->default('');
        });
    }
};
