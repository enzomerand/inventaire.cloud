<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\MemberIds;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * The members of a workspace through the API: listing them, changing a role, removing a member or leaving, handing the ownership over.
 * The rules themselves are held by MembersTest.php; this is the door: who is let in, the answers, the codes and the abilities of a token.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The address of the members of a workspace, or of one of them.
 */
function memberApiUri(Workspace $workspace, ?User $member = null): string
{
    return "/api/v1/workspaces/{$workspace->ulid}/members".($member === null ? '' : '/'.app(MemberIds::class)->of($workspace, $member));
}

/**
 * The role a person holds in a workspace now, null when they do not belong to it.
 */
function memberApiRole(Workspace $workspace, User $user): ?string
{
    $role = WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first()?->role;

    return $role?->value;
}

describe('listing the members', function (): void {
    it('gives the owner every member with their address, the owner first and then in the order they joined', function (): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['owner'], 'GET', memberApiUri($team['workspace']))->assertOk();
        $ids = app(MemberIds::class);

        expect($response->json('data'))->toBe([
            ['id' => $ids->of($team['workspace'], $team['owner']), 'name' => 'Owner', 'email' => $team['owner']->email, 'role' => 'owner', 'joined_at' => $response->json('data.0.joined_at'), 'is_self' => true],
            ['id' => $ids->of($team['workspace'], $team['editor']), 'name' => 'Editor', 'email' => $team['editor']->email, 'role' => 'editor', 'joined_at' => $response->json('data.1.joined_at'), 'is_self' => false],
            ['id' => $ids->of($team['workspace'], $team['viewer']), 'name' => 'Viewer', 'email' => $team['viewer']->email, 'role' => 'viewer', 'joined_at' => $response->json('data.2.joined_at'), 'is_self' => false],
        ])
            ->and($response->json('data.0.joined_at'))->toBeString()->and(strtotime((string) $response->json('data.0.joined_at')))->not->toBeFalse();
    });

    it('gives a member the names and the roles, and the address of nobody but themselves', function (): void {
        $team = inventoryTeam();

        foreach (['editor', 'viewer'] as $role) {
            $data = inventoryCall($this, $team[$role], 'GET', memberApiUri($team['workspace']))->assertOk()->json('data');

            expect(array_column($data, 'name'))->toBe(['Owner', 'Editor', 'Viewer'])
                ->and(array_column($data, 'role'))->toBe(['owner', 'editor', 'viewer'])
                ->and(array_column($data, 'is_self'))->toBe([false, $role === 'editor', $role === 'viewer'])
                ->and(array_column($data, 'email'))->toBe([null, $role === 'editor' ? $team['editor']->email : null, $role === 'viewer' ? $team['viewer']->email : null]);
        }
    });

    it('always has the email key, null where the address is not for the person who asks', function (): void {
        $team = inventoryTeam();

        $line = inventoryCall($this, $team['viewer'], 'GET', memberApiUri($team['workspace']))->assertOk()->json('data.0');

        expect($line)->toHaveKey('email')->and($line['email'])->toBeNull()
            ->and(json_encode($line))->not->toContain($team['owner']->email);
    });

    it('names a member by an identifier that is not the number of their account', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');

        $first = inventoryCall($this, $team['owner'], 'GET', memberApiUri($team['workspace']))->assertOk()->json('data');
        $again = inventoryCall($this, $team['editor'], 'GET', memberApiUri($team['workspace']))->assertOk()->json('data');
        $elsewhere = inventoryCall($this, $team['owner'], 'GET', memberApiUri($other))->assertOk()->json('data');

        foreach ($first as $line) {
            expect($line['id'])->toMatch('/^[0-9a-z]{26}$/');
        }

        // The same for every viewer of the workspace, another in another workspace, and no account number in sight.
        expect(array_column($again, 'id'))->toBe(array_column($first, 'id'))
            ->and($elsewhere[0]['id'])->not->toBe($first[0]['id'])
            ->and(json_encode($first))->not->toContain('"id":'.$team['owner']->id.',');
    });

    it('answers a person who does not belong to the workspace as if it did not exist', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['stranger'], 'GET', memberApiUri($team['workspace']))->assertNotFound()->assertJsonPath('message', 'Not Found');
        inventoryCall($this, $team['owner'], 'GET', '/api/v1/workspaces/01j0000000000000000000000x/members')->assertNotFound();
    });

    it('answers 401 to nobody', function (): void {
        $team = inventoryTeam();

        $this->getJson(memberApiUri($team['workspace']))->assertUnauthorized();
    });

    it('is open to a token that can read, and to nothing else', function (): void {
        $team = inventoryTeam();

        tokenCall($this, tokenFor($team['viewer'], ['read']), 'GET', memberApiUri($team['workspace']))->assertOk()->assertJsonCount(3, 'data');
        tokenCall($this, tokenFor($team['viewer'], ['write', 'manage']), 'GET', memberApiUri($team['workspace']))->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($team['stranger'], ['read']), 'GET', memberApiUri($team['workspace']))->assertNotFound();
    });
});

describe('changing the role of a member', function (): void {
    it('lets the owner make an editor a viewer, and answers the member as the owner sees them', function (): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['owner'], 'PATCH', memberApiUri($team['workspace'], $team['editor']), ['role' => 'viewer'])->assertOk();

        expect($response->json('data'))->toBe([
            'id' => app(MemberIds::class)->of($team['workspace'], $team['editor']),
            'name' => 'Editor',
            'email' => $team['editor']->email,
            'role' => 'viewer',
            'joined_at' => $response->json('data.joined_at'),
            'is_self' => false,
        ])->and(memberApiRole($team['workspace'], $team['editor']))->toBe('viewer');
    });

    it('lets the owner make a viewer an editor, and the new role is what the member may do', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['viewer'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => []])->assertForbidden();
        inventoryCall($this, $team['owner'], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'editor'])->assertOk()->assertJsonPath('data.role', 'editor');

        inventoryCall($this, $team['viewer'], 'POST', "/api/v1/inventories/{$inventory->ulid}/items", ['values' => []])->assertCreated();
    });

    it('refuses the owner role and what is not a role, with the fault under role', function (mixed $role): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['owner'], 'PATCH', memberApiUri($team['workspace'], $team['editor']), ['role' => $role])->assertUnprocessable();

        expect($response->json('errors'))->toHaveKey('role')
            ->and(memberApiRole($team['workspace'], $team['editor']))->toBe('editor');
    })->with([['owner'], ['admin'], [''], [12], [['viewer']]]);

    it('refuses a request with no role', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PATCH', memberApiUri($team['workspace'], $team['editor']))->assertUnprocessable()->assertJsonValidationErrors(['role']);
    });

    it('refuses to change the role of the owner, with the code cannot_change_owner_role', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PATCH', memberApiUri($team['workspace'], $team['owner']), ['role' => 'viewer'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cannot_change_owner_role')
            ->assertJsonStructure(['message', 'code']);

        expect(memberApiRole($team['workspace'], $team['owner']))->toBe('owner');
    });

    it('refuses a member who is not the owner with the code not_owner, before it looks at the body', function (): void {
        $team = inventoryTeam();

        foreach (['editor', 'viewer'] as $role) {
            $answer = inventoryCall($this, $team[$role], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'editor'])->assertForbidden();

            expect($answer->json())->toBe(['message' => 'Only an owner of this workspace can manage it.', 'code' => 'not_owner']);
        }

        // Not even a body that would be refused teaches them anything.
        inventoryCall($this, $team['editor'], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'owner'])->assertForbidden()->assertJsonPath('code', 'not_owner');
        expect(memberApiRole($team['workspace'], $team['viewer']))->toBe('viewer');
    });

    it('answers a person who does not belong to the workspace as if it did not exist, whatever the body', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['stranger'], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'editor'])->assertNotFound();
        inventoryCall($this, $team['stranger'], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'owner'])->assertNotFound();
        expect(memberApiRole($team['workspace'], $team['viewer']))->toBe('viewer');
    });

    it('answers 404 for an identifier that is no member of this workspace: made up, of another workspace, of somebody who left', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $other->members()->attach($team['stranger']->id, ['role' => WorkspaceRole::Viewer->value]);
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/members/";

        inventoryCall($this, $team['owner'], 'PATCH', $uri.'01j0000000000000000000000x', ['role' => 'editor'])->assertNotFound();
        inventoryCall($this, $team['owner'], 'PATCH', $uri.'not-an-identifier', ['role' => 'editor'])->assertNotFound();
        // The stranger is a member of the other workspace: the identifier they have there means nothing here.
        inventoryCall($this, $team['owner'], 'PATCH', $uri.app(MemberIds::class)->of($other, $team['stranger']), ['role' => 'editor'])->assertNotFound();
        inventoryCall($this, $team['owner'], 'PATCH', $uri.app(MemberIds::class)->of($team['workspace'], $team['stranger']), ['role' => 'editor'])->assertNotFound();
    });

    it('is a token\'s with the manage ability, never beyond the role of its owner', function (): void {
        $team = inventoryTeam();
        $uri = memberApiUri($team['workspace'], $team['editor']);

        tokenCall($this, tokenFor($team['owner'], ['read', 'write']), 'PATCH', $uri, ['role' => 'viewer'])->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($team['editor'], ['*']), 'PATCH', $uri, ['role' => 'viewer'])->assertForbidden()->assertJsonPath('code', 'not_owner');
        expect(memberApiRole($team['workspace'], $team['editor']))->toBe('editor');

        tokenCall($this, tokenFor($team['owner'], ['manage']), 'PATCH', $uri, ['role' => 'viewer'])->assertOk()->assertJsonPath('data.role', 'viewer');
        expect(memberApiRole($team['workspace'], $team['editor']))->toBe('viewer');
    });
});

describe('removing a member', function (): void {
    it('lets the owner remove a member, who loses the workspace at once', function (): void {
        $team = inventoryTeam();
        $team['editor']->forceFill(['current_workspace_id' => $team['workspace']->id])->save();

        inventoryCall($this, $team['owner'], 'DELETE', memberApiUri($team['workspace'], $team['editor']))->assertNoContent();

        expect(memberApiRole($team['workspace'], $team['editor']))->toBeNull()
            ->and($team['editor']->fresh()?->current_workspace_id)->toBeNull();

        inventoryCall($this, $team['editor'], 'GET', memberApiUri($team['workspace']))->assertNotFound();
        inventoryCall($this, $team['owner'], 'GET', memberApiUri($team['workspace']))->assertOk()->assertJsonCount(2, 'data');
    });

    it('gives a personal workspace to the person it was the only one of', function (): void {
        $team = inventoryTeam();
        $guest = User::factory()->create(['name' => 'Guest']);
        $team['workspace']->members()->attach($guest->id, ['role' => WorkspaceRole::Editor->value]);

        inventoryCall($this, $team['owner'], 'DELETE', memberApiUri($team['workspace'], $guest))->assertNoContent();

        $workspaces = $guest->workspaces()->get();

        expect($workspaces)->toHaveCount(1)->and($workspaces->first()?->id)->not->toBe($team['workspace']->id);
    });

    it('lets a member remove themselves, which is leaving the workspace', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['viewer'], 'DELETE', memberApiUri($team['workspace'], $team['viewer']))->assertNoContent();

        expect(memberApiRole($team['workspace'], $team['viewer']))->toBeNull();
        inventoryCall($this, $team['viewer'], 'GET', memberApiUri($team['workspace']))->assertNotFound();
    });

    it('refuses the owner leaving, with the code owner_cannot_leave', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'DELETE', memberApiUri($team['workspace'], $team['owner']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'owner_cannot_leave');

        expect(memberApiRole($team['workspace'], $team['owner']))->toBe('owner');
    });

    it('refuses to remove an owner who is not the person who asks, with the code cannot_remove_owner', function (): void {
        $team = inventoryTeam();
        // A workspace has one owner; this one has two (it can only be a leftover of the data), and the rule still holds.
        WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->where('user_id', $team['editor']->id)->update(['role' => WorkspaceRole::Owner->value]);

        inventoryCall($this, $team['owner'], 'DELETE', memberApiUri($team['workspace'], $team['editor']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'cannot_remove_owner');

        expect(memberApiRole($team['workspace'], $team['editor']))->toBe('owner');
    });

    it('refuses a member who is not the owner removing somebody else, with the code not_owner', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['editor'], 'DELETE', memberApiUri($team['workspace'], $team['viewer']))->assertForbidden()->assertJsonPath('code', 'not_owner');
        inventoryCall($this, $team['viewer'], 'DELETE', memberApiUri($team['workspace'], $team['editor']))->assertForbidden()->assertJsonPath('code', 'not_owner');

        expect(memberApiRole($team['workspace'], $team['viewer']))->toBe('viewer')->and(memberApiRole($team['workspace'], $team['editor']))->toBe('editor');
    });

    it('answers a person who does not belong to the workspace as if it did not exist', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['stranger'], 'DELETE', memberApiUri($team['workspace'], $team['viewer']))->assertNotFound();
        inventoryCall($this, $team['stranger'], 'DELETE', memberApiUri($team['workspace'], $team['stranger']))->assertNotFound();
        inventoryCall($this, $team['owner'], 'DELETE', memberApiUri($team['workspace'], $team['stranger']))->assertNotFound();
        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/workspaces/{$team['workspace']->ulid}/members/01j0000000000000000000000x")->assertNotFound();

        expect(WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->count())->toBe(3);
    });

    it('is a token\'s with the manage ability, for the owner and for a member who leaves', function (): void {
        $team = inventoryTeam();

        tokenCall($this, tokenFor($team['owner'], ['read', 'write']), 'DELETE', memberApiUri($team['workspace'], $team['editor']))->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        expect(memberApiRole($team['workspace'], $team['editor']))->toBe('editor');

        tokenCall($this, tokenFor($team['owner'], ['manage']), 'DELETE', memberApiUri($team['workspace'], $team['editor']))->assertNoContent();
        tokenCall($this, tokenFor($team['viewer'], ['manage']), 'DELETE', memberApiUri($team['workspace'], $team['viewer']))->assertNoContent();

        expect(memberApiRole($team['workspace'], $team['editor']))->toBeNull()->and(memberApiRole($team['workspace'], $team['viewer']))->toBeNull();
    });
});

describe('handing the ownership over', function (): void {
    it('makes the member the owner and the person who asked an editor, and answers the workspace as they see it now', function (): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/transfer", ['user_id' => app(MemberIds::class)->of($team['workspace'], $team['editor'])])->assertOk();

        expect($response->json('data'))->toBe([
            'id' => $team['workspace']->ulid,
            'name' => 'Shop',
            'role' => 'editor',
            'is_current' => true,
            'created_at' => $team['workspace']->created_at?->toIso8601String(),
        ])
            ->and(memberApiRole($team['workspace'], $team['editor']))->toBe('owner')
            ->and(memberApiRole($team['workspace'], $team['owner']))->toBe('editor')
            ->and(memberApiRole($team['workspace'], $team['viewer']))->toBe('viewer');

        // The new owner manages, the old one no longer does.
        inventoryCall($this, $team['editor'], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'editor'])->assertOk();
        inventoryCall($this, $team['owner'], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'viewer'])->assertForbidden()->assertJsonPath('code', 'not_owner');
    });

    it('can make a viewer the owner', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/transfer", ['user_id' => app(MemberIds::class)->of($team['workspace'], $team['viewer'])])->assertOk();

        expect(memberApiRole($team['workspace'], $team['viewer']))->toBe('owner')->and(memberApiRole($team['workspace'], $team['owner']))->toBe('editor');
    });

    it('refuses the owner as the new owner, with the code already_owner', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/transfer", ['user_id' => app(MemberIds::class)->of($team['workspace'], $team['owner'])])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_owner');
    });

    it('refuses an identifier that is no member of the workspace, with the fault under user_id', function (): void {
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/transfer";

        foreach (['01j0000000000000000000000x', 'nobody', app(MemberIds::class)->of($team['workspace'], $team['stranger'])] as $id) {
            $response = inventoryCall($this, $team['owner'], 'POST', $uri, ['user_id' => $id])->assertUnprocessable();

            expect($response->json('errors'))->toHaveKey('user_id')
                ->and($response->json('message'))->toBe('This person is not a member of the workspace.');
        }

        inventoryCall($this, $team['owner'], 'POST', $uri)->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        inventoryCall($this, $team['owner'], 'POST', $uri, ['user_id' => ['a']])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);

        expect(memberApiRole($team['workspace'], $team['owner']))->toBe('owner');
    });

    it('refuses a member who is not the owner with the code not_owner, and a stranger as if the workspace was not there', function (): void {
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/transfer";
        $id = app(MemberIds::class)->of($team['workspace'], $team['editor']);

        inventoryCall($this, $team['editor'], 'POST', $uri, ['user_id' => $id])->assertForbidden()->assertJsonPath('code', 'not_owner');
        inventoryCall($this, $team['viewer'], 'POST', $uri, ['user_id' => $id])->assertForbidden()->assertJsonPath('code', 'not_owner');
        inventoryCall($this, $team['stranger'], 'POST', $uri, ['user_id' => $id])->assertNotFound();
        inventoryCall($this, $team['stranger'], 'POST', $uri)->assertNotFound();

        expect(memberApiRole($team['workspace'], $team['owner']))->toBe('owner');
    });

    it('is a token\'s with the manage ability, never beyond the role of its owner', function (): void {
        $team = inventoryTeam();
        $uri = "/api/v1/workspaces/{$team['workspace']->ulid}/transfer";
        $id = app(MemberIds::class)->of($team['workspace'], $team['editor']);

        tokenCall($this, tokenFor($team['owner'], ['read', 'write']), 'POST', $uri, ['user_id' => $id])->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($team['viewer'], ['*']), 'POST', $uri, ['user_id' => $id])->assertForbidden()->assertJsonPath('code', 'not_owner');
        expect(memberApiRole($team['workspace'], $team['owner']))->toBe('owner');

        tokenCall($this, tokenFor($team['owner'], ['manage']), 'POST', $uri, ['user_id' => $id])->assertOk()->assertJsonPath('data.role', 'editor');
        expect(memberApiRole($team['workspace'], $team['editor']))->toBe('owner');
    });

    it('does not change the workspace the person works in', function (): void {
        $team = inventoryTeam();
        $mine = app(Workspaces::class)->create($team['owner'], 'Mine', true);

        tokenCall($this, tokenFor($team['owner'], ['manage']), 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/transfer", ['user_id' => app(MemberIds::class)->of($team['workspace'], $team['editor'])])->assertOk();

        expect($team['owner']->fresh()?->current_workspace_id)->toBe($mine->id);
    });
});

describe('the answers in French', function (): void {
    it('are in the language of the request, and keep their code', function (): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['editor'], 'PATCH', memberApiUri($team['workspace'], $team['viewer']), ['role' => 'editor'], ['Accept-Language' => 'fr'])->assertForbidden();

        expect($response->json('code'))->toBe('not_owner')->and($response->json('message'))->toBe('Seul un propriétaire de cet espace de travail peut le gérer.');

        $refused = inventoryCall($this, $team['owner'], 'DELETE', memberApiUri($team['workspace'], $team['owner']), headers: ['Accept-Language' => 'fr'])->assertStatus(409);

        expect($refused->json('code'))->toBe('owner_cannot_leave')->and($refused->json('message'))->toContain('propriétaire');
    });
});
