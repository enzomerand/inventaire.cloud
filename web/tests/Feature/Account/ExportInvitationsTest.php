<?php

declare(strict_types=1);

use App\Auth\AccountExport;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Workspaces\Invitations;
use App\Workspaces\Members;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('the invitations in the export of the account', function (): void {
    it('are empty lists for an account that sent and received none', function (): void {
        $export = $this->actingAs(User::factory()->create())->getJson('/api/v1/me/export', frontend())->assertOk()->json();

        expect($export)->toHaveKey('invitations')
            ->and($export['invitations'])->toBe(['sent' => [], 'received' => []])
            ->and($export['version'])->toBe(AccountExport::VERSION);
    });

    it('list those the account sent that wait for an answer: the workspace, the address, the role and the dates', function (): void {
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->speaking('en')->withPersonalWorkspace()->create(['name' => 'Ada Lovelace']);
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        app(Invitations::class)->invite($owner, $workspace, 'first@example.test', WorkspaceRole::Editor);
        app(Invitations::class)->invite($owner, $workspace, 'second@example.test', WorkspaceRole::Viewer);
        WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $workspace->id, 'invited_by_id' => $owner->id]);
        WorkspaceInvitation::factory()->create(['invited_by_id' => null]);

        $sent = app(AccountExport::class)->for($owner)['invitations']['sent'];

        expect($sent)->toBe([
            ['workspace' => ['id' => $workspace->ulid, 'name' => 'Shop'], 'email' => 'second@example.test', 'role' => 'viewer', 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addDays(14)->toIso8601String()],
            ['workspace' => ['id' => $workspace->ulid, 'name' => 'Shop'], 'email' => 'first@example.test', 'role' => 'editor', 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addDays(14)->toIso8601String()],
        ]);
    });

    it('list only the ones sent from the workspaces the account still owns: the address of a person invited is the owner\'s to see', function (): void {
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada Lovelace']);
        $kept = app(Workspaces::class)->create($owner, 'Kept');
        $handedOver = app(Workspaces::class)->create($owner, 'Handed over');
        $successor = User::factory()->withPersonalWorkspace()->create();
        $handedOver->members()->attach($successor->id, ['role' => 'editor']);
        app(Invitations::class)->invite($owner, $kept, 'kept@example.test', WorkspaceRole::Viewer);
        app(Invitations::class)->invite($owner, $handedOver, 'gone@example.test', WorkspaceRole::Viewer);
        app(Members::class)->transferOwnership($owner, $handedOver, $successor);

        $sent = app(AccountExport::class)->for($owner)['invitations']['sent'];

        expect(array_column($sent, 'email'))->toBe(['kept@example.test'])
            ->and($sent[0]['workspace']['name'])->toBe('Kept')
            // The new owner did not send it: it is not theirs to export either.
            ->and(app(AccountExport::class)->for($successor)['invitations']['sent'])->toBe([]);
    });

    it('give the identifier of the account among the members, and not its number alone', function (): void {
        $user = User::factory()->create();

        $export = app(AccountExport::class)->for($user);

        expect($export['user']['ulid'])->toBe($user->ulid)->and($export['user']['ulid'])->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/');
    });

    it('list those the account received: the name of the workspace, who invited, the role and when it lapses, and no identifier', function (): void {
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada Lovelace']);
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        $grace = User::factory()->create(['email' => 'grace@example.test']);
        app(Invitations::class)->invite($owner, $workspace, 'Grace@example.test', WorkspaceRole::Viewer);
        app(Invitations::class)->invite($owner, $workspace, 'other@example.test', WorkspaceRole::Viewer);
        WorkspaceInvitation::factory()->expired()->create(['email' => 'grace@example.test']);

        $received = app(AccountExport::class)->for($grace)['invitations']['received'];

        expect($received)->toBe([
            ['workspace' => 'Shop', 'invited_by' => 'Ada Lovelace', 'role' => 'viewer', 'expires_at' => now()->addDays(14)->toIso8601String()],
        ])->and(app(AccountExport::class)->for($grace)['invitations']['sent'])->toBe([]);
    });

    it('name nobody when the account that invited is gone', function (): void {
        $grace = User::factory()->create(['email' => 'grace@example.test']);
        WorkspaceInvitation::factory()->create(['email' => 'grace@example.test', 'invited_by_id' => null]);

        $received = app(AccountExport::class)->for($grace)['invitations']['received'];

        expect($received)->toHaveCount(1)->and($received[0]['invited_by'])->toBeNull();
    });
});
