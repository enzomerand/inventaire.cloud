<?php

declare(strict_types=1);

use App\Database\ExactEmailColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the invitations to join a workspace: an address, a role (editor or viewer) and a time limit.
 *
 * An invitation is bound to an email address, not to a secret: whoever signs in with that address (the code sent to it proves
 * they own it) sees the invitation and accepts it from the application. `email` is stored in canonical form (see
 * `App\Auth\EmailAddress`) and compared exactly on MySQL and MariaDB, like the addresses of the accounts. There is no account
 * column: an invitation addressed to somebody who has no account yet waits for them, and survives the deletion of an account
 * (it is the address that is invited, not the person).
 *
 * An invitation holds one row per workspace and address (unique): it is replaced, not duplicated, when the owner invites again.
 * `updated_at` is the moment the mail was last sent (nothing else changes a row), which is what the pause between two resends
 * is counted from. A row past its `expires_at` is invisible to everything and cleared away by `inventaire:prune-invitations`.
 * The inviter is only remembered by id: deleting the account forgets the person, the invitation stays.
 *
 * The rows hang from the workspace (cascade): one level below it, within the three levels MySQL (9.7 at the time of writing)
 * can cascade.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // The unique index below starts with the workspace: it serves the foreign key (PostgreSQL does not index one by itself).
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('role', 20);
            // PostgreSQL does not index a foreign key by itself; deleting an account nulls this column in every invitation it sent.
            $table->foreignId('invited_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
            // With a default of its own, so that MySQL < 8 and MariaDB < 10.10 (`explicit_defaults_for_timestamp` off) do not give the
            // first `NOT NULL` timestamp of a table `ON UPDATE CURRENT_TIMESTAMP`: the limit of an invitation must never move by itself.
            // It is the only `NOT NULL` timestamp here; `timestamps()` are nullable. The application always sets it.
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamps();

            // One invitation per address in a workspace; the invitations waiting for an address (sign-in, the page of invitations).
            $table->unique(['workspace_id', 'email']);
            $table->index(['email', 'expires_at']);
        });

        ExactEmailColumns::apply('workspace_invitations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
