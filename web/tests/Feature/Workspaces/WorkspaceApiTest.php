<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A user with a personal workspace.
 */
function memberOfOne(string $name = 'Ada'): User
{
    return User::factory()->withPersonalWorkspace()->create(['name' => $name]);
}

/**
 * A workspace owned by a user in which others hold roles.
 *
 * @param  array<string, WorkspaceRole>  $roles  Role by user key.
 */
function sharedWorkspace(User $owner, string $name = 'Shared', array $roles = []): Workspace
{
    $workspace = app(Workspaces::class)->create($owner, $name);

    foreach ($roles as $id => $role) {
        $workspace->members()->attach($id, ['role' => $role->value]);
    }

    return $workspace;
}

describe('without signing in', function (): void {
    it('answers 401 in JSON on every endpoint', function (string $method, string $path): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);

        $this->call($method, str_replace('{workspace}', $workspace->ulid, $path))->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    })->with([
        ['GET', '/api/v1/workspaces'],
        ['POST', '/api/v1/workspaces'],
        ['PATCH', '/api/v1/workspaces/{workspace}'],
        ['DELETE', '/api/v1/workspaces/{workspace}'],
        ['PUT', '/api/v1/me/current-workspace'],
    ]);
});

describe('listing', function (): void {
    it('lists my workspaces with my role in each and which one is current', function (): void {
        $user = memberOfOne();
        $other = memberOfOne('Grace');
        $personal = $user->workspaces()->sole();
        $shared = sharedWorkspace($other, 'Shared', [$user->id => WorkspaceRole::Viewer]);
        $user->forceFill(['current_workspace_id' => $shared->id])->save();

        $response = $this->actingAs($user)->getJson('/api/v1/workspaces', frontend())->assertOk();

        expect($response->json('data'))->toBe([
            ['id' => $personal->ulid, 'name' => 'Espace personnel', 'role' => 'owner', 'is_current' => false, 'created_at' => $personal->created_at->toIso8601String()],
            ['id' => $shared->ulid, 'name' => 'Shared', 'role' => 'viewer', 'is_current' => true, 'created_at' => $shared->created_at->toIso8601String()],
        ]);
    });

    it('never shows the workspaces of anybody else', function (): void {
        $user = memberOfOne();
        sharedWorkspace(memberOfOne('Grace'), 'Hers');

        $this->actingAs($user)->getJson('/api/v1/workspaces', frontend())->assertOk()->assertJsonCount(1, 'data');
    });

    it('marks the first workspace as current while none was chosen', function (): void {
        $user = memberOfOne();
        $user->forceFill(['current_workspace_id' => null])->save();

        $this->actingAs($user)->getJson('/api/v1/workspaces', frontend())->assertOk()->assertJsonPath('data.0.is_current', true);
    });

    it('does not expose the numeric keys', function (): void {
        $user = memberOfOne();

        $body = $this->actingAs($user)->getJson('/api/v1/workspaces', frontend())->getContent();

        expect($body)->not->toContain('"workspace_id"')->not->toContain('"pivot"');
    });
});

describe('creating', function (): void {
    it('creates a workspace I own and work in', function (): void {
        $user = memberOfOne();

        $response = $this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'Warehouse'], frontend())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Warehouse')
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.is_current', true);

        $workspace = Workspace::query()->where('ulid', $response->json('data.id'))->sole();

        expect(app(Workspaces::class)->roleOf($user, $workspace))->toBe(WorkspaceRole::Owner)
            ->and($user->fresh()->current_workspace_id)->toBe($workspace->id)
            ->and($user->workspaces()->count())->toBe(2);
    });

    it('trims the name', function (): void {
        $this->actingAs(memberOfOne())->postJson('/api/v1/workspaces', ['name' => "  \u{00A0}Warehouse\u{200B} \n"], frontend())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Warehouse');
    });

    it('accepts a name of 100 characters, counted as characters and not bytes', function (): void {
        $this->actingAs(memberOfOne())->postJson('/api/v1/workspaces', ['name' => str_repeat('é', 100)], frontend())->assertCreated();
    });

    it('refuses a name that is missing, empty, blank, too long or not text', function (mixed $name): void {
        $user = memberOfOne();

        $this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => $name], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        expect($user->workspaces()->count())->toBe(1);
    })->with([
        'empty' => [''],
        'blank' => ["   \t "],
        'invisible' => ["\u{200B}\u{00A0}"],
        'null' => [null],
        'too long' => [str_repeat('a', 101)],
        'too long after trimming' => [' '.str_repeat('é', 101).' '],
        'a number' => [42],
        'a list' => [['a']],
    ]);

    it('refuses a request without a name', function (): void {
        $this->actingAs(memberOfOne())->postJson('/api/v1/workspaces', [], frontend())->assertUnprocessable()->assertJsonValidationErrors('name');
    });

    it('is written in the language of the request', function (): void {
        $this->actingAs(memberOfOne())->postJson('/api/v1/workspaces', ['name' => ''], frontend(['Accept-Language' => 'en']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    });
});

describe('renaming', function (): void {
    it('lets an owner rename a workspace', function (): void {
        $user = memberOfOne();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user)->patchJson("/api/v1/workspaces/{$workspace->ulid}", ['name' => ' Home '], frontend())
            ->assertOk()
            ->assertJsonPath('data.id', $workspace->ulid)
            ->assertJsonPath('data.name', 'Home')
            ->assertJsonPath('data.role', 'owner');

        expect($workspace->fresh()->name)->toBe('Home');
    });

    it('lets a second owner rename it too', function (): void {
        $owner = memberOfOne();
        $second = memberOfOne('Grace');
        $workspace = sharedWorkspace($owner, 'Shared', [$second->id => WorkspaceRole::Owner]);

        $this->actingAs($second)->patchJson("/api/v1/workspaces/{$workspace->ulid}", ['name' => 'Renamed'], frontend())->assertOk();
    });

    it('forbids an editor and a viewer', function (WorkspaceRole $role): void {
        $owner = memberOfOne();
        $member = memberOfOne('Grace');
        $workspace = sharedWorkspace($owner, 'Shared', [$member->id => $role]);

        $this->actingAs($member)->patchJson("/api/v1/workspaces/{$workspace->ulid}", ['name' => 'Mine now'], frontend())->assertForbidden();

        expect($workspace->fresh()->name)->toBe('Shared');
    })->with([WorkspaceRole::Editor, WorkspaceRole::Viewer]);

    it('says a workspace of somebody else does not exist', function (): void {
        $workspace = sharedWorkspace(memberOfOne(), 'Hers');

        $this->actingAs(memberOfOne('Grace'))->patchJson("/api/v1/workspaces/{$workspace->ulid}", ['name' => 'Mine now'], frontend())->assertNotFound();

        expect($workspace->fresh()->name)->toBe('Hers');
    });

    it('does not let a stranger or a viewer learn anything from the validation', function (): void {
        $workspace = sharedWorkspace(memberOfOne(), 'Hers');
        $viewer = memberOfOne('Grace');
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs(memberOfOne('Eve'))->patchJson("/api/v1/workspaces/{$workspace->ulid}", ['name' => ''], frontend())->assertNotFound();
        $this->actingAs($viewer)->patchJson("/api/v1/workspaces/{$workspace->ulid}", ['name' => ''], frontend())->assertForbidden();
    });

    it('answers 404 for a workspace that does not exist or is not a ULID', function (string $ulid): void {
        $this->actingAs(memberOfOne())->patchJson("/api/v1/workspaces/{$ulid}", ['name' => 'Home'], frontend())->assertNotFound();
    })->with(['01hzzzzzzzzzzzzzzzzzzzzzzz', '12', 'not-a-ulid']);

    it('validates the name', function (): void {
        $user = memberOfOne();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user)->patchJson("/api/v1/workspaces/{$workspace->ulid}", ['name' => str_repeat('a', 101)], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        expect($workspace->fresh()->name)->toBe('Espace personnel');
    });
});

describe('deleting', function (): void {
    it('lets an owner delete a workspace, and everything in it', function (): void {
        $user = memberOfOne();
        $shop = sharedWorkspace($user, 'Shop');

        $this->actingAs($user)->deleteJson("/api/v1/workspaces/{$shop->ulid}", [], frontend())->assertNoContent();

        expect(Workspace::query()->whereKey($shop->id)->exists())->toBeFalse()
            ->and($user->workspaces()->count())->toBe(1);
    });

    it('switches to another workspace when it was the current one', function (): void {
        $user = memberOfOne();
        $personal = $user->workspaces()->sole();
        $shop = sharedWorkspace($user, 'Shop');
        expect($user->fresh()->current_workspace_id)->toBe($shop->id);

        $this->actingAs($user->fresh())->deleteJson("/api/v1/workspaces/{$shop->ulid}", [], frontend())->assertNoContent();

        expect($user->fresh()->current_workspace_id)->toBe($personal->id);
        $this->actingAs($user->fresh())->getJson('/api/v1/workspaces', frontend())->assertJsonPath('data.0.is_current', true);
    });

    it('refuses to delete the last workspace, with a code', function (): void {
        $user = memberOfOne();
        $only = $user->workspaces()->sole();

        $this->actingAs($user)->deleteJson("/api/v1/workspaces/{$only->ulid}", [], frontend(['Accept-Language' => 'en']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_workspace')
            ->assertJsonPath('message', 'You cannot delete your last workspace: create another one first.');

        expect(Workspace::query()->whereKey($only->id)->exists())->toBeTrue();
    });

    it('counts the workspaces I belong to without owning', function (): void {
        $user = memberOfOne();
        $personal = $user->workspaces()->sole();
        sharedWorkspace(memberOfOne('Grace'), 'Hers', [$user->id => WorkspaceRole::Viewer]);

        $this->actingAs($user)->deleteJson("/api/v1/workspaces/{$personal->ulid}", [], frontend())->assertNoContent();
    });

    it('forbids an editor and a viewer', function (WorkspaceRole $role): void {
        $member = memberOfOne('Grace');
        $workspace = sharedWorkspace(memberOfOne(), 'Shared', [$member->id => $role]);

        $this->actingAs($member)->deleteJson("/api/v1/workspaces/{$workspace->ulid}", [], frontend())->assertForbidden();

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    })->with([WorkspaceRole::Editor, WorkspaceRole::Viewer]);

    it('says a workspace of somebody else does not exist', function (): void {
        $workspace = sharedWorkspace(memberOfOne(), 'Hers');

        $this->actingAs(memberOfOne('Grace'))->deleteJson("/api/v1/workspaces/{$workspace->ulid}", [], frontend())->assertNotFound();

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });
});

describe('choosing the current workspace', function (): void {
    it('switches to one I belong to, and says so', function (): void {
        $user = memberOfOne();
        $personal = $user->workspaces()->sole();
        sharedWorkspace($user, 'Shop');

        $this->actingAs($user->fresh())->putJson('/api/v1/me/current-workspace', ['workspace' => $personal->ulid], frontend())
            ->assertOk()
            ->assertJsonPath('data.id', $personal->ulid)
            ->assertJsonPath('data.is_current', true);

        expect($user->fresh()->current_workspace_id)->toBe($personal->id);
    });

    it('works for a workspace shared with me as a viewer', function (): void {
        $user = memberOfOne();
        $shared = sharedWorkspace(memberOfOne('Grace'), 'Hers', [$user->id => WorkspaceRole::Viewer]);

        $this->actingAs($user)->putJson('/api/v1/me/current-workspace', ['workspace' => $shared->ulid], frontend())
            ->assertOk()
            ->assertJsonPath('data.role', 'viewer');

        expect($user->fresh()->current_workspace_id)->toBe($shared->id);
    });

    it('refuses a workspace I do not belong to, as it does one that does not exist', function (): void {
        $user = memberOfOne();
        $personal = $user->workspaces()->sole();
        $hers = sharedWorkspace(memberOfOne('Grace'), 'Hers');

        $stranger = $this->actingAs($user)->putJson('/api/v1/me/current-workspace', ['workspace' => $hers->ulid], frontend(['Accept-Language' => 'en']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workspace');
        $unknown = $this->actingAs($user)->putJson('/api/v1/me/current-workspace', ['workspace' => '01hzzzzzzzzzzzzzzzzzzzzzzz'], frontend(['Accept-Language' => 'en']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workspace');

        expect($stranger->json())->toBe($unknown->json())
            ->and($user->fresh()->current_workspace_id)->toBe($personal->id);
    });

    it('refuses a missing or malformed workspace', function (mixed $workspace): void {
        $this->actingAs(memberOfOne())->putJson('/api/v1/me/current-workspace', ['workspace' => $workspace], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workspace');
    })->with([null, '', 'x', 12, [['a']]]);

    it('does not take the workspace of somebody else into account in my choice after I left it', function (): void {
        $user = memberOfOne();
        $hers = sharedWorkspace(memberOfOne('Grace'), 'Hers', [$user->id => WorkspaceRole::Viewer]);
        $hers->members()->detach($user->id);

        $this->actingAs($user)->putJson('/api/v1/me/current-workspace', ['workspace' => $hers->ulid], frontend())->assertUnprocessable();
    });
});
