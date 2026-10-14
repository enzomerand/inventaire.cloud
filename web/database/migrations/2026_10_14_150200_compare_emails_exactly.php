<?php

declare(strict_types=1);

use App\Database\ExactEmailColumns;
use Illuminate\Database\Migrations\Migration;

/**
 * Makes the database compare email addresses exactly on MySQL and MariaDB.
 *
 * Their default collations ignore accents (`gmäil.com` equals `gmail.com`), so
 * a lookalike domain could be taken for the real one. Addresses are stored in
 * canonical form (lowercase, domain in punycode) by the application; this
 * second layer makes sure the database itself never confuses two addresses.
 * PostgreSQL already compares exactly: nothing changes there.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        ExactEmailColumns::apply('users');
        ExactEmailColumns::apply('login_codes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ExactEmailColumns::revert('login_codes');
        ExactEmailColumns::revert('users');
    }
};
