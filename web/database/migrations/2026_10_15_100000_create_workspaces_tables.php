<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the workspaces, who belongs to them with which role, and the workspace each user is working in.
 *
 * A workspace has a numeric key for the database and a ULID for the outside world (URLs, API). Deleting a user or
 * a workspace deletes the memberships that mention it; deleting the workspace a user is working in only forgets
 * their choice (the application then falls back to another one).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            // A key of its own, since a table without a primary key is refused by some managed database servers.
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            // "owner", "editor" or "viewer" (App\Workspaces\WorkspaceRole): a string, so a role can be added without a migration.
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_workspace_id');
        });

        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
