<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a personal workspace to every account that has none, which is every account that existed before
 * workspaces did.
 *
 * Idempotent: it only looks at accounts without a membership, so running it again (or on an instance whose accounts
 * all have one) changes nothing. It uses the tables directly rather than the models, which will evolve while this
 * migration must keep doing what it did. The workspace is named in the language of the account, or the language of
 * the application for an account that has none.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $default = config()->string('app.locale');

        DB::table('users')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('workspace_user')->whereColumn('workspace_user.user_id', 'users.id'))
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($default): void {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, $default): void {
                        $now = now();
                        $locale = is_string($user->locale) && $user->locale !== '' ? $user->locale : $default;

                        $workspace = DB::table('workspaces')->insertGetId([
                            'ulid' => strtolower((string) Str::ulid()),
                            'name' => __('Personal space', [], $locale),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('workspace_user')->insert([
                            'workspace_id' => $workspace,
                            'user_id' => $user->id,
                            'role' => 'owner',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('users')->where('id', $user->id)->update(['current_workspace_id' => $workspace]);
                    });
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: the workspaces it made are dropped with their tables, and a workspace that someone created
     * since is not this migration's to remove.
     */
    public function down(): void {}
};
