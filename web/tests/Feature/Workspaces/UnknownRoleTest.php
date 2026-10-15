<?php

declare(strict_types=1);

use App\Auth\AccountDeletion;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\StoredRole;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(fn () => StoredRole::forgetReported());

/**
 * A member of a workspace of somebody else whose role in the database is a word this version does not know (written
 * by a newer version after a rollback, or edited by hand).
 *
 * @return array{0: User, 1: Workspace}
 */
function memberWithUnknownRole(string $role = 'supervisor'): array
{
    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace = app(Workspaces::class)->create(User::factory()->withPersonalWorkspace()->create(), 'Hers');
    DB::table('workspace_user')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

    return [$member, $workspace];
}

describe('a role the database holds but this version does not know', function (): void {
    it('is read as a viewer, the role with the fewest rights, instead of failing', function (string $stored): void {
        [$member, $workspace] = memberWithUnknownRole($stored);

        $role = app(Workspaces::class)->roleOf($member, $workspace);
        $membership = app(Workspaces::class)->membership($member, $workspace);

        expect($role)->toBe(WorkspaceRole::Viewer)
            ->and($membership?->role)->toBe(WorkspaceRole::Viewer)
            ->and(array_map(fn ($membership): string => $membership->role->value, app(Workspaces::class)->memberships($member)))->toBe(['owner', 'viewer'])
            ->and(WorkspaceMember::query()->where('user_id', $member->id)->where('workspace_id', $workspace->id)->sole()->getRawOriginal('role'))->toBe($stored);
    })->with(['an unknown word' => ['supervisor'], 'a role from the future' => ['Owner '], 'empty' => ['']]);

    it('is reported once, with the value, however many times it is read', function (): void {
        Log::spy();
        [$member, $workspace] = memberWithUnknownRole();

        app(Workspaces::class)->memberships($member);
        app(Workspaces::class)->memberships($member);
        app(Workspaces::class)->roleOf($member, $workspace);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['role' => 'supervisor']);
    });

    it('is reported once per distinct value', function (): void {
        Log::spy();
        [$first] = memberWithUnknownRole('supervisor');
        [$second] = memberWithUnknownRole('auditor');

        app(Workspaces::class)->memberships($first);
        app(Workspaces::class)->memberships($second);
        app(Workspaces::class)->memberships($first);

        Log::shouldHaveReceived('warning')->twice();
    });

    it('does not keep the page with the workspace switcher from rendering', function (): void {
        [$member] = memberWithUnknownRole();

        $page = $this->actingAs($member)->get(route('app'), ['Accept-Language' => 'en'])->assertOk();

        preg_match('/data-workspaces="([^"]*)"/', $page->getContent(), $match);
        $items = json_decode(html_entity_decode($match[1] ?? '[]', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($items, 'role', 'name'))->toBe(['Espace personnel' => 'owner', 'Hers' => 'viewer']);
        $this->flushSession();
        $this->actingAs($member)->getJson('/api/v1/workspaces', frontend())->assertOk()->assertJsonPath('data.1.role', 'viewer');
    });

    it('gives the rights of a viewer only', function (): void {
        [$member, $workspace] = memberWithUnknownRole();

        expect(Gate::forUser($member)->allows('view', $workspace))->toBeTrue()
            ->and(Gate::forUser($member)->allows('update', $workspace))->toBeFalse()
            ->and(Gate::forUser($member)->allows('delete', $workspace))->toBeFalse()
            ->and(Gate::forUser($member)->allows('manage', $workspace))->toBeFalse()
            ->and(fn () => Gate::forUser($member)->authorize('delete', $workspace))->toThrow(AuthorizationException::class);
    });

    it('is never an owner: it does not hold a workspace back from the one who leaves, nor stand in for one', function (): void {
        $leaving = User::factory()->withPersonalWorkspace()->create();
        $member = User::factory()->withPersonalWorkspace()->create();
        $workspace = app(Workspaces::class)->create($leaving, 'Shared');
        DB::table('workspace_user')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'owner-ish', 'created_at' => now(), 'updated_at' => now()]);

        // The only owner is the one who leaves, and the other member counts as a viewer: the workspace needs an owner.
        expect(fn () => app(AccountDeletion::class)->ensureDeletable($leaving))->toThrow(AccountDeletionRefused::class);
    });

    it('is stored as it is given: an enum case or a plain word', function (): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $user = User::factory()->create();
        $other = User::factory()->create();

        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Editor]);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $other->id, 'role' => 'viewer']);

        expect(DB::table('workspace_user')->where('workspace_id', $workspace->id)->orderBy('id')->pluck('role')->all())->toBe(['editor', 'viewer']);
    });
});

describe('a role that is written to the database', function (): void {
    it('is refused when this version does not know it, and nothing is written', function (mixed $role): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $user = User::factory()->create();

        expect(fn () => WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]))
            ->toThrow(InvalidArgumentException::class, 'cannot hold the role')
            ->and(DB::table('workspace_user')->where('user_id', $user->id)->exists())->toBeFalse();
    })->with([
        'an unknown word' => ['supervisor'],
        'a known word in another case' => ['Owner'],
        'a known word with a space' => ['owner '],
        'empty' => [''],
        'nothing' => [null],
        'a number' => [3],
        'a list' => [['owner']],
    ]);

    it('is refused when it is assigned to a membership that already exists, which keeps its role', function (): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $user = User::factory()->create();
        $membership = WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Editor]);

        expect(function () use ($membership): void {
            $membership->role = 'supervisor';
        })->toThrow(InvalidArgumentException::class)
            ->and($membership->fresh()?->role)->toBe(WorkspaceRole::Editor);
    });

    it('can be any role this version knows, as a case or as its word', function (WorkspaceRole $role): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $byCase = User::factory()->create();
        $byWord = User::factory()->create();

        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $byCase->id, 'role' => $role]);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $byWord->id, 'role' => $role->value]);

        expect(DB::table('workspace_user')->where('workspace_id', $workspace->id)->orderBy('id')->pluck('role')->all())->toBe([$role->value, $role->value]);
    })->with(WorkspaceRole::cases());
});
