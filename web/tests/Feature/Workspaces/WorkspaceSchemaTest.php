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
        expect(Schema::getColumnListing('workspaces'))->toEqualCanonicalizing(['id', 'ulid', 'name', 'created_at', 'updated_at'])
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
