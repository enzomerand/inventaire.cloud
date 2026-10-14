<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table of pending sign-in codes: at most one per email address, stored as a keyed hash.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            // Addresses are stored in lowercase (PostgreSQL compares case-sensitively, MySQL and MariaDB do not).
            $table->string('email')->primary();
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
        Schema::dropIfExists('login_codes');
    }
};
