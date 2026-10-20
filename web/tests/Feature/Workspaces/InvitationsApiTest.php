<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * The invitations through the API: what an owner sends and manages, and what the person they are addressed to answers. The rules
 * themselves are held by InvitationsTest.php; this is the door: who is let in, the answers, the codes, the pace and the abilities of a token.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The address of the invitations a workspace has sent, or of one of them.
 */
function invitationApiUri(Workspace $workspace, ?WorkspaceInvitation $invitation = null, string $suffix = ''): string
{
    return "/api/v1/workspaces/{$workspace->ulid}/invitations".($invitation === null ? '' : '/'.$invitation->ulid.$suffix);
}

/**
 * An invitation to an address, as the table holds it (nothing is mailed).
 *
 * @param  array<string, mixed>  $attributes
 */
function invitationApiFor(Workspace $workspace, string $email, array $attributes = []): WorkspaceInvitation
{
    return WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'email' => $email] + $attributes);
}

/**
 * A person with a personal workspace whose address is given, so that invitations to it are theirs.
 */
function invitationApiPerson(string $email, string $name = 'Guest'): User
{
    return User::factory()->withPersonalWorkspace()->create(['name' => $name, 'email' => $email]);
}

/**
 * The messages sent to an address so far.
 *
 * @return list<string>
 */
function invitationApiMailsTo(string $email): array
{
    $found = [];

    foreach (sentMails() as $mail) {
        foreach ($mail->getTo() as $to) {
            if ($to->getAddress() === $email) {
                $found[] = (string) $mail->getSubject();
            }
        }
    }

    return $found;
}

describe('without signing in', function (): void {
    it('answers 401 in JSON on every endpoint', function (string $method, string $path): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $invitation = invitationApiFor($workspace, 'guest@example.test');

        $this->call($method, str_replace(['{workspace}', '{invitation}'], [$workspace->ulid, $invitation->ulid], $path))->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    })->with([
        ['GET', '/api/v1/workspaces/{workspace}/invitations'],
        ['POST', '/api/v1/workspaces/{workspace}/invitations'],
        ['POST', '/api/v1/workspaces/{workspace}/invitations/{invitation}/resend'],
        ['DELETE', '/api/v1/workspaces/{workspace}/invitations/{invitation}'],
        ['GET', '/api/v1/me/invitations'],
        ['POST', '/api/v1/me/invitations/{invitation}/accept'],
        ['DELETE', '/api/v1/me/invitations/{invitation}'],
        ['GET', '/api/v1/workspaces/{workspace}/members'],
        ['PATCH', '/api/v1/workspaces/{workspace}/members/01j0000000000000000000000x'],
        ['DELETE', '/api/v1/workspaces/{workspace}/members/01j0000000000000000000000x'],
        ['POST', '/api/v1/workspaces/{workspace}/transfer'],
    ]);
});

describe('listing the invitations a workspace has sent', function (): void {
    it('gives the owner the ones that wait for an answer, the newest first', function (): void {
        $team = inventoryTeam();
        $older = invitationApiFor($team['workspace'], 'older@example.test', ['invited_by_id' => $team['owner']->id, 'role' => 'viewer']);
        $newer = invitationApiFor($team['workspace'], 'newer@example.test', ['invited_by_id' => $team['editor']->id]);

        $response = inventoryCall($this, $team['owner'], 'GET', invitationApiUri($team['workspace']))->assertOk();

        expect($response->json('data'))->toBe([
            [
                'id' => $newer->ulid,
                'email' => 'newer@example.test',
                'role' => 'editor',
                'invited_by' => ['id' => $team['editor']->ulid, 'name' => 'Editor'],
                'expires_at' => $newer->expires_at->toIso8601String(),
                'created_at' => $newer->created_at?->toIso8601String(),
            ],
            [
                'id' => $older->ulid,
                'email' => 'older@example.test',
                'role' => 'viewer',
                'invited_by' => ['id' => $team['owner']->ulid, 'name' => 'Owner'],
                'expires_at' => $older->expires_at->toIso8601String(),
                'created_at' => $older->created_at?->toIso8601String(),
            ],
        ]);
    });

    it('has no sender for an invitation whose sender is gone', function (): void {
        $team = inventoryTeam();
        invitationApiFor($team['workspace'], 'guest@example.test');

        inventoryCall($this, $team['owner'], 'GET', invitationApiUri($team['workspace']))->assertOk()->assertJsonPath('data.0.invited_by', null);
    });

    it('leaves out an invitation that lapsed, and those of other workspaces', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        invitationApiFor($team['workspace'], 'lapsed@example.test', ['expires_at' => now()->subSecond()]);
        invitationApiFor($other, 'elsewhere@example.test');
        $mine = invitationApiFor($team['workspace'], 'waiting@example.test');

        $data = inventoryCall($this, $team['owner'], 'GET', invitationApiUri($team['workspace']))->assertOk()->json('data');

        expect(array_column($data, 'id'))->toBe([$mine->ulid]);
    });

    it('is the owner\'s alone: a member is refused with the code not_owner, a stranger is told the workspace is not there', function (): void {
        $team = inventoryTeam();
        invitationApiFor($team['workspace'], 'guest@example.test');

        inventoryCall($this, $team['editor'], 'GET', invitationApiUri($team['workspace']))->assertForbidden()->assertJsonPath('code', 'not_owner');
        inventoryCall($this, $team['viewer'], 'GET', invitationApiUri($team['workspace']))->assertForbidden()->assertJsonPath('code', 'not_owner');
        inventoryCall($this, $team['stranger'], 'GET', invitationApiUri($team['workspace']))->assertNotFound();
    });

    it('is open to a token that can read, and to nothing else', function (): void {
        $team = inventoryTeam();

        tokenCall($this, tokenFor($team['owner'], ['read']), 'GET', invitationApiUri($team['workspace']))->assertOk()->assertJsonPath('data', []);
        tokenCall($this, tokenFor($team['owner'], ['write', 'manage']), 'GET', invitationApiUri($team['workspace']))->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($team['editor'], ['*']), 'GET', invitationApiUri($team['workspace']))->assertForbidden()->assertJsonPath('code', 'not_owner');
    });
});

describe('inviting', function (): void {
    it('creates the invitation, answers it, and mails the address after the answer', function (): void {
        $this->freezeTime();
        $team = inventoryTeam();
        $held = holdDeferred();

        $response = inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['email' => '  Nouveau@Example.TEST ', 'role' => 'editor'])->assertCreated();
        $stored = WorkspaceInvitation::query()->sole();

        expect($response->json('data'))->toBe([
            'id' => $stored->ulid,
            'email' => 'nouveau@example.test',
            'role' => 'editor',
            'invited_by' => ['id' => $team['owner']->ulid, 'name' => 'Owner'],
            'expires_at' => now()->addDays(14)->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ])
            ->and($stored->workspace_id)->toBe($team['workspace']->id)->and($stored->invited_by_id)->toBe($team['owner']->id)
            ->and(invitationApiMailsTo('nouveau@example.test'))->toBe([]);

        $held->release();

        expect(invitationApiMailsTo('nouveau@example.test'))->toHaveCount(1);
    });

    it('invites a viewer as well, and an address that has an account', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');

        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['email' => $guest->email, 'role' => 'viewer'])->assertCreated()->assertJsonPath('data.role', 'viewer');

        inventoryCall($this, $guest, 'GET', '/api/v1/me/invitations')->assertOk()->assertJsonCount(1, 'data');
    });

    it('refuses an address that is not valid, a role that is not an invited one, and a body with a part missing, with the fault under its key', function (array $body, string $key): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), $body)->assertUnprocessable();

        expect($response->json('errors'))->toHaveKey($key)->and(WorkspaceInvitation::query()->count())->toBe(0);
    })->with([
        'no address' => [['role' => 'editor'], 'email'],
        'not an address' => [['email' => 'nobody', 'role' => 'editor'], 'email'],
        'an accent in the local part' => [['email' => 'élodie@example.test', 'role' => 'editor'], 'email'],
        'an address in a list' => [['email' => ['a@example.test'], 'role' => 'editor'], 'email'],
        'no role' => [['email' => 'guest@example.test'], 'role'],
        'the owner role' => [['email' => 'guest@example.test', 'role' => 'owner'], 'role'],
        'a role that does not exist' => [['email' => 'guest@example.test', 'role' => 'admin'], 'role'],
    ]);

    it('refuses an address of a member, the owner\'s included, with the code already_member', function (): void {
        $team = inventoryTeam();

        foreach (['owner', 'viewer'] as $who) {
            inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['email' => strtoupper($team[$who]->email), 'role' => 'editor'])
                ->assertStatus(409)
                ->assertJsonPath('code', 'already_member');
        }

        expect(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('refuses an address that has a waiting invitation, with the code already_invited', function (): void {
        $team = inventoryTeam();
        invitationApiFor($team['workspace'], 'guest@example.test');

        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['email' => 'Guest@Example.test', 'role' => 'viewer'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_invited');
    });

    it('refuses an invitation past the places of the workspace, with the code member_limit_reached and the limit', function (): void {
        $team = inventoryTeam();
        config(['workspaces.max_members' => 4]);
        invitationApiFor($team['workspace'], 'first@example.test');

        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['email' => 'second@example.test', 'role' => 'viewer'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'A workspace cannot have more than 4 members, counting the invitations waiting for an answer.', 'code' => 'member_limit_reached', 'limit' => 4]);
    });

    it('refuses a member who is not the owner with the code not_owner, before it looks at the body, and a stranger as if the workspace was not there', function (): void {
        $team = inventoryTeam();

        foreach (['editor', 'viewer'] as $role) {
            inventoryCall($this, $team[$role], 'POST', invitationApiUri($team['workspace']), ['email' => 'guest@example.test', 'role' => 'editor'])->assertForbidden()->assertJsonPath('code', 'not_owner');
            inventoryCall($this, $team[$role], 'POST', invitationApiUri($team['workspace']), ['email' => 'nobody', 'role' => 'owner'])->assertForbidden()->assertJsonPath('code', 'not_owner');
        }

        inventoryCall($this, $team['stranger'], 'POST', invitationApiUri($team['workspace']), ['email' => 'guest@example.test', 'role' => 'editor'])->assertNotFound();
        inventoryCall($this, $team['stranger'], 'POST', invitationApiUri($team['workspace']))->assertNotFound();

        expect(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('is a token\'s with the manage ability, never beyond the role of its owner', function (): void {
        $team = inventoryTeam();
        $body = ['email' => 'guest@example.test', 'role' => 'editor'];

        tokenCall($this, tokenFor($team['owner'], ['read', 'write']), 'POST', invitationApiUri($team['workspace']), $body)->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($team['editor'], ['*']), 'POST', invitationApiUri($team['workspace']), $body)->assertForbidden()->assertJsonPath('code', 'not_owner');
        expect(WorkspaceInvitation::query()->count())->toBe(0);

        tokenCall($this, tokenFor($team['owner'], ['manage']), 'POST', invitationApiUri($team['workspace']), $body)->assertCreated();
        expect(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('speaks French to a request in French, with the same code', function (): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['email' => $team['viewer']->email, 'role' => 'editor'], ['Accept-Language' => 'fr'])->assertStatus(409);

        expect($response->json())->toBe(['message' => "Cette personne appartient déjà à l'espace de travail.", 'code' => 'already_member']);
    });
});

describe('the pace of the invitations', function (): void {
    /**
     * Invites an address as the owner of the team.
     */
    function invitationApiSend(object $test, array $team, string $email): TestResponse
    {
        return inventoryCall($test, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['email' => $email, 'role' => 'viewer']);
    }

    it('lets an owner send workspaces.invitations_per_hour of them an hour, then answers 429 with Retry-After', function (): void {
        $team = inventoryTeam();
        config(['workspaces.invitations_per_hour' => 2]);

        invitationApiSend($this, $team, 'one@example.test')->assertCreated();
        invitationApiSend($this, $team, 'two@example.test')->assertCreated();
        $refused = invitationApiSend($this, $team, 'three@example.test')->assertStatus(429);

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and(WorkspaceInvitation::query()->count())->toBe(2);

        // The hour passes: they can send again.
        $this->travel(61)->minutes();
        invitationApiSend($this, $team, 'three@example.test')->assertCreated();
    });

    it('counts the invitations sent again with the others', function (): void {
        $team = inventoryTeam();
        config(['workspaces.invitations_per_hour' => 2]);
        $old = invitationApiFor($team['workspace'], 'old@example.test', ['updated_at' => now()->subHour()]);

        invitationApiSend($this, $team, 'one@example.test')->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $old, '/resend'))->assertOk();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $old, '/resend'))->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    });

    it('counts it for the owner, not for the workspace', function (): void {
        $team = inventoryTeam();
        $mine = app(Workspaces::class)->create($team['owner'], 'Mine');
        config(['workspaces.invitations_per_hour' => 1]);

        invitationApiSend($this, $team, 'one@example.test')->assertCreated();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($mine), ['email' => 'two@example.test', 'role' => 'viewer'])->assertStatus(429);

        // Another owner has a pace of their own.
        $other = app(Workspaces::class)->create($team['editor'], 'Theirs');
        inventoryCall($this, $team['editor'], 'POST', invitationApiUri($other), ['email' => 'two@example.test', 'role' => 'viewer'])->assertCreated();
    });

    it('lets an address receive workspaces.invitations_per_address_per_day of them a day, from everybody together, then answers 429 with Retry-After', function (): void {
        $team = inventoryTeam();
        config(['workspaces.invitations_per_address_per_day' => 2]);
        $theirs = app(Workspaces::class)->create($team['editor'], 'Theirs');
        $mine = app(Workspaces::class)->create($team['owner'], 'Mine');

        invitationApiSend($this, $team, 'guest@example.test')->assertCreated();
        // The same address in capitals and padded, sent by another owner: it is one address.
        inventoryCall($this, $team['editor'], 'POST', invitationApiUri($theirs), ['email' => '  Guest@Example.TEST ', 'role' => 'viewer'])->assertCreated();
        $refused = inventoryCall($this, $team['owner'], 'POST', invitationApiUri($mine), ['email' => 'guest@example.test', 'role' => 'viewer'])->assertStatus(429);

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and(WorkspaceInvitation::query()->where('workspace_id', $mine->id)->count())->toBe(0);

        // Another address is another count, and the day passes.
        invitationApiSend($this, $team, 'other@example.test')->assertCreated();
        $this->travel(25)->hours();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($mine), ['email' => 'guest@example.test', 'role' => 'viewer'])->assertCreated();
    });

    it('counts the invitations sent again for their address, and keeps no address in the cache', function (): void {
        $team = inventoryTeam();
        config(['workspaces.invitations_per_address_per_day' => 1]);
        $old = invitationApiFor($team['workspace'], 'guest@example.test', ['updated_at' => now()->subHour()]);

        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $old, '/resend'))->assertOk();

        // Ten minutes later the invitation could be sent again; the address has had its mail for the day.
        $this->travel(11)->minutes();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $old, '/resend'))->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

        $keys = json_encode(array_keys((fn (): array => $this->storage)->call(app('cache')->getStore())), JSON_THROW_ON_ERROR);
        expect($keys)->not->toContain('guest@example.test')->and($keys)->not->toContain('guest%40example.test');
    });

    it('does not count a request that names no address, or an invitation that is not there', function (): void {
        $team = inventoryTeam();
        config(['workspaces.invitations_per_address_per_day' => 1]);

        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']), ['role' => 'viewer'])->assertUnprocessable();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']).'/01j0000000000000000000000x/resend')->assertNotFound();
        invitationApiSend($this, $team, 'guest@example.test')->assertCreated();
    });

    it('is not used up by a request that is refused before it gets that far', function (): void {
        $team = inventoryTeam();
        config(['workspaces.invitations_per_hour' => 1]);
        $body = ['email' => 'guest@example.test', 'role' => 'viewer'];

        // A token that cannot manage, and a member who is not the owner: neither did anything.
        for ($try = 0; $try < 3; $try++) {
            tokenCall($this, tokenFor($team['owner'], ['read']), 'POST', invitationApiUri($team['workspace']), $body)->assertForbidden();
        }

        tokenCall($this, tokenFor($team['owner'], ['manage']), 'POST', invitationApiUri($team['workspace']), $body)->assertCreated();
    });
});

describe('sending an invitation again', function (): void {
    it('mails it once more and gives it another term', function (): void {
        $this->freezeTime();
        $team = inventoryTeam();
        $invitation = invitationApiFor($team['workspace'], 'guest@example.test', ['updated_at' => now()->subHour(), 'expires_at' => now()->addDay(), 'invited_by_id' => $team['editor']->id]);
        $held = holdDeferred();

        $response = inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $invitation, '/resend'))->assertOk();

        expect($response->json('data'))->toBe([
            'id' => $invitation->ulid,
            'email' => 'guest@example.test',
            'role' => 'editor',
            'invited_by' => ['id' => $team['owner']->ulid, 'name' => 'Owner'],
            'expires_at' => now()->addDays(14)->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ])->and(invitationApiMailsTo('guest@example.test'))->toBe([]);

        $held->release();

        expect(invitationApiMailsTo('guest@example.test'))->toHaveCount(1);
    });

    it('refuses to send it again within ten minutes, with the code resend_too_soon and the seconds to wait', function (): void {
        $this->freezeTime();
        $team = inventoryTeam();
        $invitation = invitationApiFor($team['workspace'], 'guest@example.test', ['updated_at' => now()->subMinutes(4)]);

        $response = inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $invitation, '/resend'))->assertStatus(429);

        expect($response->json())->toBe([
            'message' => 'This invitation was sent a moment ago: wait a few minutes before sending it again.',
            'code' => 'resend_too_soon',
            'retry_after' => 360,
        ])
            ->and($response->headers->get('Retry-After'))->toBe('360')
            ->and(invitationApiMailsTo('guest@example.test'))->toBe([]);

        $this->travel(361)->seconds();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $invitation, '/resend'))->assertOk();
    });

    it('answers 404 for an invitation of another workspace, one that lapsed, and one that is not there', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = invitationApiFor($other, 'elsewhere@example.test', ['updated_at' => now()->subHour()]);
        $lapsed = invitationApiFor($team['workspace'], 'lapsed@example.test', ['updated_at' => now()->subHour(), 'expires_at' => now()->subSecond()]);

        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $elsewhere, '/resend'))->assertNotFound();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace'], $lapsed, '/resend'))->assertNotFound();
        inventoryCall($this, $team['owner'], 'POST', invitationApiUri($team['workspace']).'/01j0000000000000000000000x/resend')->assertNotFound();
        expect(sentMails())->toBe([]);
    });

    it('is the owner\'s alone', function (): void {
        $team = inventoryTeam();
        $invitation = invitationApiFor($team['workspace'], 'guest@example.test', ['updated_at' => now()->subHour()]);

        inventoryCall($this, $team['editor'], 'POST', invitationApiUri($team['workspace'], $invitation, '/resend'))->assertForbidden()->assertJsonPath('code', 'not_owner');
        inventoryCall($this, $team['stranger'], 'POST', invitationApiUri($team['workspace'], $invitation, '/resend'))->assertNotFound();
    });

    it('is a token\'s with the manage ability', function (): void {
        $team = inventoryTeam();
        $invitation = invitationApiFor($team['workspace'], 'guest@example.test', ['updated_at' => now()->subHour()]);
        $uri = invitationApiUri($team['workspace'], $invitation, '/resend');

        tokenCall($this, tokenFor($team['owner'], ['read', 'write']), 'POST', $uri)->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($team['owner'], ['manage']), 'POST', $uri)->assertOk();
    });
});

describe('withdrawing an invitation', function (): void {
    it('deletes it, and it can no longer be accepted', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], $guest->email);

        inventoryCall($this, $team['owner'], 'DELETE', invitationApiUri($team['workspace'], $invitation))->assertNoContent();

        expect(WorkspaceInvitation::query()->count())->toBe(0);
        inventoryCall($this, $guest, 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")->assertNotFound();
        inventoryCall($this, $team['owner'], 'DELETE', invitationApiUri($team['workspace'], $invitation))->assertNotFound();
    });

    it('answers 404 for an invitation of another workspace, and leaves it', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = invitationApiFor($other, 'elsewhere@example.test');

        inventoryCall($this, $team['owner'], 'DELETE', invitationApiUri($team['workspace'], $elsewhere))->assertNotFound();

        expect(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('is the owner\'s alone', function (): void {
        $team = inventoryTeam();
        $invitation = invitationApiFor($team['workspace'], 'guest@example.test');

        inventoryCall($this, $team['editor'], 'DELETE', invitationApiUri($team['workspace'], $invitation))->assertForbidden()->assertJsonPath('code', 'not_owner');
        inventoryCall($this, $team['stranger'], 'DELETE', invitationApiUri($team['workspace'], $invitation))->assertNotFound();

        expect(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('is a token\'s with the manage ability', function (): void {
        $team = inventoryTeam();
        $invitation = invitationApiFor($team['workspace'], 'guest@example.test');

        tokenCall($this, tokenFor($team['owner'], ['read', 'write']), 'DELETE', invitationApiUri($team['workspace'], $invitation))->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($team['owner'], ['manage']), 'DELETE', invitationApiUri($team['workspace'], $invitation))->assertNoContent();
    });
});

describe('the invitations addressed to me', function (): void {
    it('lists the ones that wait for an answer from my address, the newest first, with the workspace and who sent them', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $other = app(Workspaces::class)->create($team['editor'], 'Annex');
        $older = invitationApiFor($team['workspace'], $guest->email, ['invited_by_id' => $team['owner']->id, 'role' => 'viewer']);
        $newer = invitationApiFor($other, $guest->email, ['invited_by_id' => $team['editor']->id]);

        $response = inventoryCall($this, $guest, 'GET', '/api/v1/me/invitations')->assertOk();

        expect($response->json('data'))->toBe([
            [
                'id' => $newer->ulid,
                'workspace' => ['id' => $other->ulid, 'name' => 'Annex'],
                'role' => 'editor',
                'inviter' => ['name' => 'Editor'],
                'expires_at' => $newer->expires_at->toIso8601String(),
            ],
            [
                'id' => $older->ulid,
                'workspace' => ['id' => $team['workspace']->ulid, 'name' => 'Shop'],
                'role' => 'viewer',
                'inviter' => ['name' => 'Owner'],
                'expires_at' => $older->expires_at->toIso8601String(),
            ],
        ]);
    });

    it('has no sender for an invitation whose sender is gone, and never gives the account of the sender', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        invitationApiFor($team['workspace'], $guest->email, ['invited_by_id' => $team['owner']->id]);
        invitationApiFor(app(Workspaces::class)->create($team['editor'], 'Annex'), $guest->email);

        $text = inventoryCall($this, $guest, 'GET', '/api/v1/me/invitations')->assertOk()->getContent();

        expect($text)->toContain('"inviter":null')->and($text)->not->toContain($team['owner']->email);
    });

    it('leaves out the ones addressed to somebody else, and the ones that lapsed', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        invitationApiFor($team['workspace'], 'somebody@example.test');
        invitationApiFor($team['workspace'], $guest->email, ['expires_at' => now()->subSecond()]);

        inventoryCall($this, $guest, 'GET', '/api/v1/me/invitations')->assertOk()->assertExactJson(['data' => []]);
        inventoryCall($this, $team['owner'], 'GET', '/api/v1/me/invitations')->assertOk()->assertExactJson(['data' => []]);
    });

    it('is open to a token that can read, and to nothing else', function (): void {
        $guest = invitationApiPerson('guest@example.test');

        tokenCall($this, tokenFor($guest, ['read']), 'GET', '/api/v1/me/invitations')->assertOk();
        tokenCall($this, tokenFor($guest, ['write', 'manage']), 'GET', '/api/v1/me/invitations')->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
    });
});

describe('accepting an invitation', function (): void {
    it('makes me a member with the invited role, spends the invitation and answers the workspace as I see it', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $current = $guest->current_workspace_id;
        $invitation = invitationApiFor($team['workspace'], $guest->email, ['role' => 'viewer']);

        $response = inventoryCall($this, $guest, 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")->assertOk();

        expect($response->json('data'))->toBe([
            'id' => $team['workspace']->ulid,
            'name' => 'Shop',
            'role' => 'viewer',
            'is_current' => false,
            'created_at' => $team['workspace']->created_at?->toIso8601String(),
        ])
            ->and(memberApiRoleOf($team['workspace'], $guest))->toBe('viewer')
            ->and(WorkspaceInvitation::query()->count())->toBe(0)
            ->and($guest->fresh()?->current_workspace_id)->toBe($current);

        // The workspace is theirs from now on, and answering again finds nothing.
        inventoryCall($this, $guest, 'GET', "/api/v1/workspaces/{$team['workspace']->ulid}/members")->assertOk()->assertJsonCount(4, 'data');
        inventoryCall($this, $guest, 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")->assertNotFound();
    });

    it('does not let me accept an invitation addressed to somebody else, and leaves it', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], 'somebody@example.test');

        inventoryCall($this, $guest, 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")->assertNotFound()->assertJsonPath('message', 'Not Found');
        inventoryCall($this, $team['owner'], 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")->assertNotFound();
        inventoryCall($this, $guest, 'POST', '/api/v1/me/invitations/01j0000000000000000000000x/accept')->assertNotFound();

        expect(WorkspaceInvitation::query()->count())->toBe(1)->and(memberApiRoleOf($team['workspace'], $guest))->toBeNull();
    });

    it('answers 404 for an invitation that lapsed', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], $guest->email, ['expires_at' => now()->subSecond()]);

        inventoryCall($this, $guest, 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")->assertNotFound();

        expect(memberApiRoleOf($team['workspace'], $guest))->toBeNull();
    });

    it('only spends the invitation of a person who belongs to the workspace already, and keeps their role', function (): void {
        $team = inventoryTeam();
        $invitation = invitationApiFor($team['workspace'], $team['viewer']->email, ['role' => 'editor']);

        inventoryCall($this, $team['viewer'], 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")->assertOk()->assertJsonPath('data.role', 'viewer');

        expect(WorkspaceInvitation::query()->count())->toBe(0)->and(memberApiRoleOf($team['workspace'], $team['viewer']))->toBe('viewer');
    });

    it('refuses a workspace that is full, with the code member_limit_reached and the limit, and keeps the invitation', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], $guest->email);
        config(['workspaces.max_members' => 3]);

        inventoryCall($this, $guest, 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'A workspace cannot have more than 3 members, counting the invitations waiting for an answer.', 'code' => 'member_limit_reached', 'limit' => 3]);

        expect(WorkspaceInvitation::query()->count())->toBe(1)->and(memberApiRoleOf($team['workspace'], $guest))->toBeNull();
    });

    it('refuses a person who belongs to as many workspaces as they may, with the code membership_limit_reached and the limit', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], $guest->email);
        config(['workspaces.max_per_user' => 1]);

        inventoryCall($this, $guest, 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'You cannot belong to more than 1 workspace.', 'code' => 'membership_limit_reached', 'limit' => 1]);

        expect(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('is a token\'s with the manage ability, and does not change the workspace its owner works in', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $current = $guest->current_workspace_id;
        $invitation = invitationApiFor($team['workspace'], $guest->email);
        $uri = "/api/v1/me/invitations/{$invitation->ulid}/accept";

        tokenCall($this, tokenFor($guest, ['read', 'write']), 'POST', $uri)->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        expect(WorkspaceInvitation::query()->count())->toBe(1);

        tokenCall($this, tokenFor($guest, ['manage']), 'POST', $uri)->assertOk()->assertJsonPath('data.id', $team['workspace']->ulid);
        expect($guest->fresh()?->current_workspace_id)->toBe($current);
    });
});

describe('declining an invitation', function (): void {
    it('deletes it', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], $guest->email);

        inventoryCall($this, $guest, 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}")->assertNoContent();

        expect(WorkspaceInvitation::query()->count())->toBe(0)->and(memberApiRoleOf($team['workspace'], $guest))->toBeNull();
        inventoryCall($this, $guest, 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}")->assertNotFound();
    });

    it('does not let me decline an invitation addressed to somebody else, the owner who sent it included, and leaves it', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], 'somebody@example.test');

        inventoryCall($this, $guest, 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}")->assertNotFound();
        inventoryCall($this, $team['owner'], 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}")->assertNotFound();

        expect(WorkspaceInvitation::query()->count())->toBe(1);
    });

    it('answers 404 for an invitation that lapsed', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], $guest->email, ['expires_at' => now()->subSecond()]);

        inventoryCall($this, $guest, 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}")->assertNotFound();
    });

    it('is a token\'s with the manage ability', function (): void {
        $team = inventoryTeam();
        $guest = invitationApiPerson('guest@example.test');
        $invitation = invitationApiFor($team['workspace'], $guest->email);

        tokenCall($this, tokenFor($guest, ['read', 'write']), 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}")->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, tokenFor($guest, ['manage']), 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}")->assertNoContent();
    });
});

/**
 * The role a person holds in a workspace now, null when they do not belong to it.
 */
function memberApiRoleOf(Workspace $workspace, User $user): ?string
{
    $role = WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first()?->role;

    return $role instanceof WorkspaceRole ? $role->value : null;
}
