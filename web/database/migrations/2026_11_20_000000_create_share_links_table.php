<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the share links of an inventory: a secret address that shows the inventory, read-only, to anybody who holds it.
 *
 * The token in the address is 32 random bytes (43 characters, base64url) and is never stored as it is. `token_hash` is its SHA-256 and
 * the only way a visitor's link is looked up (unique, so one lookup is one index probe). `token_encrypted` holds the token again,
 * encrypted with the application key, so that the owner can copy the address again later; when it cannot be decrypted (the key was
 * changed) the owner is told to make a new link. A leaked database dump therefore holds no address that works.
 *
 * `include_files` says whether the files and photos of the items are shown and served through the link. `expires_at` is null for a link
 * that never expires; a row past it is refused everywhere and kept for a while, listed to its owner as expired, then cleared away by
 * `inventaire:prune-share-links`. `last_used_at` is moved at most once an hour (a conditional update), not on every view. The author is
 * only remembered by id: deleting the account forgets the person, not the link.
 *
 * The rows hang from the inventory (cascade), which is at the second level below a workspace: the chain stays within the three levels
 * MySQL (9.7 at the time of writing) can cascade.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // PostgreSQL does not index a foreign key by itself; listing the links of an inventory and deleting an inventory need it.
            $table->foreignId('inventory_id')->index()->constrained()->cascadeOnDelete();
            // PostgreSQL does not index a foreign key by itself; deleting an account nulls this column in every link it made.
            $table->foreignId('created_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('label', 80)->nullable();
            $table->boolean('include_files')->default(true);
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            // All nullable: no `NOT NULL` timestamp for MySQL < 8 and MariaDB < 10.10 (`explicit_defaults_for_timestamp` off) to give an
            // `ON UPDATE CURRENT_TIMESTAMP` to. The application sets them.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // The pruning (`inventaire:prune-share-links`) looks for the links that expired long ago, whoever made them.
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
