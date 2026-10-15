<?php

declare(strict_types=1);

use App\Workspaces\WorkspaceRole;

it('has the three roles, stored as plain words', function (): void {
    expect(array_map(fn (WorkspaceRole $role): string => $role->value, WorkspaceRole::cases()))->toBe(['owner', 'editor', 'viewer'])
        ->and(WorkspaceRole::from('editor'))->toBe(WorkspaceRole::Editor)
        ->and(WorkspaceRole::tryFrom('admin'))->toBeNull();
});

it('lets every role see, the editors and owners edit, and only the owners manage', function (WorkspaceRole $role, bool $view, bool $edit, bool $manage): void {
    expect($role->canView())->toBe($view)
        ->and($role->canEdit())->toBe($edit)
        ->and($role->canManage())->toBe($manage);
})->with([
    'owner' => [WorkspaceRole::Owner, true, true, true],
    'editor' => [WorkspaceRole::Editor, true, true, false],
    'viewer' => [WorkspaceRole::Viewer, true, false, false],
]);
