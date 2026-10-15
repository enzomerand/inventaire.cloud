<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * A workspace and a user who holds the role in it (null: a stranger to it).
 *
 * @return array{0: User, 1: Workspace}
 */
function userWithRole(?WorkspaceRole $role): array
{
    $workspace = Workspace::query()->create(['name' => 'Shop']);
    $user = User::factory()->create();

    if ($role !== null) {
        $workspace->members()->attach($user->id, ['role' => $role->value]);
    }

    return [$user, $workspace];
}

it('lets every member see the workspace, and nobody else', function (?WorkspaceRole $role, bool $allowed): void {
    [$user, $workspace] = userWithRole($role);

    expect(Gate::forUser($user)->allows('view', $workspace))->toBe($allowed);
})->with([
    'owner' => [WorkspaceRole::Owner, true],
    'editor' => [WorkspaceRole::Editor, true],
    'viewer' => [WorkspaceRole::Viewer, true],
    'stranger' => [null, false],
]);

it('lets only an owner change, delete and manage the workspace', function (string $ability, ?WorkspaceRole $role, bool $allowed): void {
    [$user, $workspace] = userWithRole($role);

    expect(Gate::forUser($user)->allows($ability, $workspace))->toBe($allowed);
})->with(function (): Generator {
    foreach (['update', 'delete', 'manage'] as $ability) {
        yield "{$ability} as owner" => [$ability, WorkspaceRole::Owner, true];
        yield "{$ability} as editor" => [$ability, WorkspaceRole::Editor, false];
        yield "{$ability} as viewer" => [$ability, WorkspaceRole::Viewer, false];
        yield "{$ability} as stranger" => [$ability, null, false];
    }
});

it('forbids a member who lacks the right, and says the workspace is not there to a stranger', function (string $ability): void {
    [$viewer, $workspace] = userWithRole(WorkspaceRole::Viewer);
    $stranger = User::factory()->create();

    $forbidden = fn () => Gate::forUser($viewer)->authorize($ability, $workspace);
    $unknown = fn () => Gate::forUser($stranger)->authorize($ability, $workspace);

    expect($forbidden)->toThrow(AuthorizationException::class, 'Seul un propriétaire');

    try {
        $unknown();
    } catch (AuthorizationException $exception) {
        expect($exception->status())->toBe(404);

        return;
    }

    throw new RuntimeException('A stranger was not refused.');
})->with(['update', 'delete', 'manage']);

it('lets anybody who is signed in create a workspace', function (): void {
    expect(Gate::forUser(User::factory()->create())->allows('create', Workspace::class))->toBeTrue();
});
