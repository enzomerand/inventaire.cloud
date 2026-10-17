<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * The workspace a person works in is their choice, made at their browser: `PUT /me/current-workspace` is not for a token. What a token
 * does with workspaces (creating one, deleting one) must not make that choice for them either.
 */

/**
 * A person with their personal workspace as the one they work in, and a second one they own.
 *
 * @return array{user: User, personal: Workspace, shared: Workspace}
 */
function tokenTwoWorkspaces(bool $sharedIsCurrent = false): array
{
    $user = inventoryPerson('Ada');
    $personal = $user->workspaces()->firstOrFail();
    $shared = app(Workspaces::class)->create($user, 'Shared');

    if (! $sharedIsCurrent) {
        app(Workspaces::class)->makeCurrent($user, $personal);
    }

    return ['user' => $user, 'personal' => $personal, 'shared' => $shared];
}

describe('a workspace made with a token', function (): void {
    it('does not become the workspace the person works in', function (): void {
        $made = tokenTwoWorkspaces();

        $response = tokenCall($this, tokenFor($made['user'], ['manage']), 'POST', '/api/v1/workspaces', ['name' => 'Garage'])
            ->assertCreated()
            ->assertJsonPath('data.is_current', false);

        expect($made['user']->fresh()->current_workspace_id)->toBe($made['personal']->id)
            ->and(Workspace::query()->where('ulid', $response->json('data.id'))->exists())->toBeTrue();
    });

    it('becomes the one they work in when it is made from their session, as before', function (): void {
        $made = tokenTwoWorkspaces();

        $response = inventoryCall($this, $made['user'], 'POST', '/api/v1/workspaces', ['name' => 'Garage'])
            ->assertCreated()
            ->assertJsonPath('data.is_current', true);

        expect($made['user']->fresh()->current_workspace_id)->toBe(Workspace::query()->where('ulid', $response->json('data.id'))->value('id'));
    });
});

describe('a workspace deleted with a token', function (): void {
    it('leaves the choice of the person as it is when it was another workspace', function (): void {
        $made = tokenTwoWorkspaces();

        tokenCall($this, tokenFor($made['user'], ['manage']), 'DELETE', "/api/v1/workspaces/{$made['shared']->ulid}")->assertNoContent();

        expect($made['user']->fresh()->current_workspace_id)->toBe($made['personal']->id);
    });

    it('does not choose another for the person when it was the one they worked in: the database forgets it and the first one they joined is used', function (): void {
        $made = tokenTwoWorkspaces(sharedIsCurrent: true);

        tokenCall($this, tokenFor($made['user'], ['manage']), 'DELETE', "/api/v1/workspaces/{$made['shared']->ulid}")->assertNoContent();

        expect($made['user']->fresh()->current_workspace_id)->toBeNull();

        inventoryCall($this, $made['user'], 'GET', '/api/v1/workspaces')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $made['personal']->ulid)
            ->assertJsonPath('data.0.is_current', true);
    });

    it('gives the person another one from their session, as before', function (): void {
        $made = tokenTwoWorkspaces(sharedIsCurrent: true);

        inventoryCall($this, $made['user'], 'DELETE', "/api/v1/workspaces/{$made['shared']->ulid}")->assertNoContent();

        expect($made['user']->fresh()->current_workspace_id)->toBe($made['personal']->id);
    });
});
