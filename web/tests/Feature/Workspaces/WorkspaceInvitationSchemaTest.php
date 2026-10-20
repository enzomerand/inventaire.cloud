<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Inserts an invitation the way a careless script would: straight into the table, with no normalization.
 */
function rawInvitation(Workspace $workspace, string $email): void
{
    DB::table('workspace_invitations')->insert([
        'ulid' => (string) Str::ulid(),
        'workspace_id' => $workspace->id,
        'email' => $email,
        'role' => 'viewer',
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('the table of invitations', function (): void {
    it('has the columns the application relies on', function (): void {
        expect(Schema::getColumnListing('workspace_invitations'))
            ->toEqualCanonicalizing(['id', 'ulid', 'workspace_id', 'email', 'role', 'invited_by_id', 'expires_at', 'created_at', 'updated_at']);
    });

    it('gives an invitation a ULID of its own, in lowercase, beside its numeric key', function (): void {
        $invitation = WorkspaceInvitation::factory()->create();

        expect($invitation->id)->toBeInt()
            ->and($invitation->ulid)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
            ->and($invitation->getRouteKey())->toBe($invitation->ulid)
            ->and(WorkspaceInvitation::factory()->create()->ulid)->not->toBe($invitation->ulid);
    });

    it('stores the address in canonical form, whatever creates the invitation', function (): void {
        $invitation = WorkspaceInvitation::factory()->create(['email' => '  Ada@Example.TEST ']);

        expect($invitation->fresh()->email)->toBe('ada@example.test');
    });

    it('refuses two invitations for one address in a workspace, but not the same address in two workspaces', function (): void {
        $invitation = WorkspaceInvitation::factory()->create(['email' => 'ada@example.test']);
        WorkspaceInvitation::factory()->create(['email' => 'ada@example.test']);

        expect(fn () => WorkspaceInvitation::factory()->create(['workspace_id' => $invitation->workspace_id, 'email' => 'ada@example.test']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('compares addresses exactly: two addresses that differ by an accent are two addresses', function (): void {
        $workspace = Workspace::factory()->create();

        rawInvitation($workspace, 'jose@example.test');
        rawInvitation($workspace, 'josé@example.test');

        expect(WorkspaceInvitation::query()->count())->toBe(2)
            ->and(DB::table('workspace_invitations')->where('email', 'josé@example.test')->count())->toBe(1)
            ->and(DB::table('workspace_invitations')->where('email', 'jose@example.test')->count())->toBe(1);
    });

    it('goes with its workspace', function (): void {
        $invitation = WorkspaceInvitation::factory()->create();

        $invitation->workspace->delete();

        expect(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('forgets who sent it when that account is deleted, and stays', function (): void {
        $sender = User::factory()->create();
        $invitation = WorkspaceInvitation::factory()->create(['invited_by_id' => $sender->id]);

        DB::table('users')->delete();

        expect($invitation->fresh()->invited_by_id)->toBeNull();
    });

    it('is an application table, so that the installer does not take it for a foreign one', function (): void {
        expect(ApplicationTables::owns('workspace_invitations'))->toBeTrue();
    });
});
