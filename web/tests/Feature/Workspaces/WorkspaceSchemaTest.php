<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('the tables', function (): void {
    it('have the columns the application relies on', function (): void {
        expect(Schema::getColumnListing('workspaces'))->toEqualCanonicalizing(['id', 'ulid', 'name', 'storage_used', 'photo_entry_enabled', 'created_at', 'updated_at'])
            ->and(Schema::getColumnListing('workspace_user'))->toEqualCanonicalizing(['id', 'workspace_id', 'user_id', 'role', 'created_at', 'updated_at'])
            ->and(Schema::hasColumn('users', 'current_workspace_id'))->toBeTrue();
    });

    it('give a workspace a ULID of its own, in lowercase, beside its numeric key', function (): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);

        expect($workspace->id)->toBeInt()
            ->and($workspace->ulid)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
            ->and($workspace->fresh()->ulid)->toBe($workspace->ulid)
            ->and($workspace->getRouteKey())->toBe($workspace->ulid)
            ->and(Workspace::query()->create(['name' => 'Shop'])->ulid)->not->toBe($workspace->ulid);
    });

    it('refuse two workspaces with the same ULID', function (): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);

        DB::table('workspaces')->insert(['ulid' => $workspace->ulid, 'name' => 'Copy', 'created_at' => now(), 'updated_at' => now()]);
    })->throws(UniqueConstraintViolationException::class);

    it('refuse a user twice in the same workspace', function (): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $user = User::factory()->create();

        $workspace->members()->attach($user->id, ['role' => 'owner']);
        $workspace->members()->attach($user->id, ['role' => 'viewer']);
    })->throws(UniqueConstraintViolationException::class);

    it('let a user belong to several workspaces, each with its role', function (): void {
        $user = User::factory()->create();
        $mine = Workspace::query()->create(['name' => 'Mine']);
        $theirs = Workspace::query()->create(['name' => 'Theirs']);
        $mine->members()->attach($user->id, ['role' => 'owner']);
        $theirs->members()->attach($user->id, ['role' => 'viewer']);

        $roles = $user->workspaces->mapWithKeys(fn (Workspace $workspace): array => [$workspace->name => $workspace->pivot->role]);

        expect($roles->all())->toBe(['Mine' => WorkspaceRole::Owner, 'Theirs' => WorkspaceRole::Viewer]);
    });
});

describe('the migrations', function (): void {
    it('go back and forth', function (): void {
        $workspaces = require database_path('migrations/2026_10_15_100000_create_workspaces_tables.php');
        $deletionCodes = require database_path('migrations/2026_10_15_300000_create_account_deletion_codes_table.php');
        // Inventories hang from workspaces: they go first on the way down and come last on the way up.
        $inventories = require database_path('migrations/2026_10_20_100000_create_inventories_tables.php');
        // The history and the files hang from items, and the storage counter from workspaces: they go first as well.
        $revisions = require database_path('migrations/2026_10_22_000000_create_item_revisions_table.php');
        $attachments = require database_path('migrations/2026_10_22_000100_create_attachments_table.php');
        $storage = require database_path('migrations/2026_10_22_000200_add_storage_used_to_workspaces.php');
        // The exports hang from inventories and accounts.
        $exports = require database_path('migrations/2026_10_23_000000_create_exports_table.php');
        // So do the imports.
        $imports = require database_path('migrations/2026_10_30_000000_create_imports_table.php');
        // And the official versions (which also add the counter of their numbers to the inventories).
        $versions = require database_path('migrations/2026_11_06_000000_create_inventory_versions_table.php');
        // The invitations hang from workspaces and accounts.
        $invitations = require database_path('migrations/2026_11_13_000000_create_workspace_invitations_table.php');
        // The share links hang from inventories and accounts.
        $shareLinks = require database_path('migrations/2026_11_20_000000_create_share_links_table.php');
        // The analyses of photos hang from inventories and accounts.
        $photoAnalyses = require database_path('migrations/2026_12_01_000000_create_photo_analyses_table.php');
        // The consent to the photo entry is a column of workspaces: it goes before them and comes back after them.
        $photoEntry = require database_path('migrations/2026_12_01_000100_add_photo_entry_enabled_to_workspaces_table.php');
        // The billing account of a workspace hangs from it.
        $billingAccounts = require database_path('migrations/2026_12_02_000000_create_billing_accounts_table.php');
        // The columns of the contract mails and the withdrawals (which point to workspaces and accounts) come after the billing accounts.
        $contract = require database_path('migrations/2026_12_03_000000_add_contract_notices_to_billing_accounts_and_create_billing_withdrawals.php');
        $contract->down();
        $billingAccounts->down();
        $photoEntry->down();
        $photoAnalyses->down();
        $shareLinks->down();
        $invitations->down();
        $versions->down();
        $imports->down();
        $exports->down();
        $attachments->down();
        $revisions->down();
        $inventories->down();
        $deletionCodes->down();
        $workspaces->down();

        try {
            expect(Schema::hasTable('workspaces'))->toBeFalse()
                ->and(Schema::hasTable('inventories'))->toBeFalse()
                ->and(Schema::hasTable('workspace_user'))->toBeFalse()
                ->and(Schema::hasTable('account_deletion_codes'))->toBeFalse()
                ->and(Schema::hasColumn('users', 'current_workspace_id'))->toBeFalse();
        } finally {
            $workspaces->up();
            $storage->up();
            $deletionCodes->up();
            $inventories->up();
            $revisions->up();
            $attachments->up();
            $exports->up();
            $imports->up();
            $versions->up();
            $invitations->up();
            $shareLinks->up();
            $photoAnalyses->up();
            $photoEntry->up();
            $billingAccounts->up();
            $contract->up();
        }

        expect(Schema::hasTable('workspaces'))->toBeTrue()
            ->and(Schema::hasTable('inventories'))->toBeTrue()
            ->and(Schema::hasTable('workspace_user'))->toBeTrue()
            ->and(Schema::hasTable('account_deletion_codes'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'current_workspace_id'))->toBeTrue();
    });
});

describe('deleting', function (): void {
    it('a user deletes their memberships, with a raw query too', function (): void {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        DB::table('users')->delete();

        expect(WorkspaceMember::query()->count())->toBe(0)
            // The workspace itself is not the user's: deleting an account is the application's job (see AccountDeletion).
            ->and(Workspace::query()->count())->toBe(1);
    });

    it('a workspace deletes its memberships and only forgets the choice of the users who were working in it', function (): void {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $workspace->members()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $workspace->delete();

        expect(WorkspaceMember::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(1)
            ->and($user->fresh()->current_workspace_id)->toBeNull();
    });

    it('refuses a current workspace that does not exist', function (): void {
        $user = User::factory()->create();

        $user->forceFill(['current_workspace_id' => 999_999])->save();
    })->throws(QueryException::class);
});
