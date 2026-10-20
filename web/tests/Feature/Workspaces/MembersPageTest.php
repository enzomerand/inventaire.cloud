<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The page of the members of a workspace (`/app/w/{workspace}/members`): who may open it, and what each role is offered. The list and
 * the actions go through the API (MembersApiTest and InvitationsApiTest hold the rules); the page frames them, so what is checked here is
 * the markup the scripts rely on, and that what only the owner may do is not even rendered for the others.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The address of the members page of a workspace.
 */
function membersPageUri(Workspace $workspace): string
{
    return route('app.workspace.members', ['workspace' => $workspace->ulid], absolute: false);
}

/**
 * The messages the layout hands to the scripts of a page, as the page embeds them.
 *
 * @return array<string, string>
 */
function membersPageMessages(TestResponse $page): array
{
    preg_match('#<script type="application/json" id="client-messages">(.*?)</script>#s', $page->getContent(), $match);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

describe('who may open the page', function (): void {
    it('is at /app/w/{workspace}/members, behind the same checks as the other pages of the space', function (): void {
        expect(route('app.workspace.members', ['workspace' => 'ws'], absolute: false))->toBe('/app/w/ws/members');

        $middleware = Route::getRoutes()->getByName('app.workspace.members')?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class);
    });

    it('sends a visitor to the login page', function (): void {
        $team = inventoryTeam();

        $this->get(membersPageUri($team['workspace']))->assertRedirect(route('login'));
    });

    it('is shown to every member of the workspace', function (string $who): void {
        $team = inventoryTeam();

        $this->actingAs($team[$who])->get(membersPageUri($team['workspace']))
            ->assertOk()
            ->assertSee('x-data="workspaceMembers"', false)
            ->assertSee('data-url="/api/v1/workspaces/'.$team['workspace']->ulid.'/members"', false);
    })->with(['the owner' => 'owner', 'an editor' => 'editor', 'a viewer' => 'viewer']);

    it('answers as if the workspace did not exist to somebody who does not belong to it', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['stranger'])->get(membersPageUri($team['workspace']))->assertNotFound();
    });

    it('answers a workspace that does not exist with a 404 too', function (): void {
        $this->actingAs(inventoryPerson())->get('/app/w/01JZZZZZZZZZZZZZZZZZZZZZZZ/members')->assertNotFound();
    });

    it('makes the workspace the current one of the account, like the other pages of a workspace', function (): void {
        $user = inventoryPerson();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        $garage = app(Workspaces::class)->create($user->fresh(), 'Garage');

        $this->actingAs($user->fresh())->get(route('app'))->assertRedirect(route('app.workspace', ['workspace' => $garage->ulid]));

        $this->actingAs($user->fresh())->get(membersPageUri($shop))->assertOk();

        expect($user->fresh()->current_workspace_id)->toBe($shop->id);
        $this->actingAs($user->fresh())->get(route('app'))->assertRedirect(route('app.workspace', ['workspace' => $shop->ulid]));
    });

    it('does not make a workspace the current one for somebody who is refused', function (): void {
        $team = inventoryTeam();
        $before = $team['stranger']->current_workspace_id;

        $this->actingAs($team['stranger'])->get(membersPageUri($team['workspace']))->assertNotFound();

        expect($team['stranger']->fresh()->current_workspace_id)->toBe($before);
    });

    it('marks its workspace in the switcher of the header', function (): void {
        $user = inventoryPerson();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        app(Workspaces::class)->create($user->fresh(), 'Garage');

        $this->actingAs($user->fresh())->get(membersPageUri($shop))
            ->assertOk()
            ->assertSee('x-text="currentName">Shop</span>', false);
    });
});

describe('what the owner is offered', function (): void {
    it('is the controls of the members, the form of an invitation and the invitations that wait', function (): void {
        $team = inventoryTeam();
        $ws = $team['workspace']->ulid;

        $this->actingAs($team['owner'])->get(membersPageUri($team['workspace']))
            ->assertOk()
            ->assertSee('data-is-owner="1"', false)
            ->assertSee('data-transfer-url="/api/v1/workspaces/'.$ws.'/transfer"', false)
            ->assertSee('data-home-url="/app"', false)
            ->assertSee('data-workspace-name="Shop"', false)
            ->assertSee('@change="changeRole(member, $event)"', false)
            ->assertSee('data-member-action="transfer"', false)
            ->assertSee('data-member-action="remove"', false)
            ->assertSee('x-data="workspaceInvitations"', false)
            ->assertSee('data-url="/api/v1/workspaces/'.$ws.'/invitations"', false)
            ->assertSee('Invite someone')
            ->assertSee('Send the invitation')
            ->assertSee('Invitations waiting for an answer')
            ->assertSee('To leave this workspace, hand its ownership over to another member first.')
            ->assertDontSee('data-focus="leave"', false);
    });

    it('has the form of an invitation: an address of 255 characters at most, and an editor or a viewer', function (): void {
        $team = inventoryTeam();

        $page = $this->actingAs($team['owner'])->get(membersPageUri($team['workspace']))->assertOk();
        $html = $page->getContent();

        expect($html)->toMatch('/<input[^>]*id="member-invite-email"[^>]*type="email"|<input[^>]*type="email"[^>]*id="member-invite-email"/s')
            ->and($html)->toMatch('/id="member-invite-email"[^>]*maxlength="255"|maxlength="255"[^>]*id="member-invite-email"/s')
            ->and($html)->toMatch('/<select[^>]*id="member-invite-role".*?<option value="editor">Editor<\/option>\s*<option value="viewer">Viewer<\/option>/s')
            ->and($html)->not->toContain('<option value="owner"');
    });

    it('links back to the workspace', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(membersPageUri($team['workspace']))
            ->assertOk()
            ->assertSee('href="/app/w/'.$team['workspace']->ulid.'"', false)
            ->assertSee('Back to Shop');
    });
});

describe('what the other members are offered', function (): void {
    it('is the list and the way to leave, and nothing that only the owner may do', function (string $who): void {
        $team = inventoryTeam();

        $this->actingAs($team[$who])->get(membersPageUri($team['workspace']))
            ->assertOk()
            ->assertSee('data-is-owner="0"', false)
            ->assertSee('data-focus="leave"', false)
            ->assertSee('data-focus="confirmLeave"', false)
            ->assertSee('Leave this workspace')
            // None of the controls of the owner is in the markup: the API refuses them anyway, but there is nothing to tamper with.
            ->assertDontSee('x-data="workspaceInvitations"', false)
            ->assertDontSee('@change="changeRole(member, $event)"', false)
            ->assertDontSee('data-member-action', false)
            ->assertDontSee('member-invite-email', false)
            ->assertDontSee('Invite someone')
            ->assertDontSee('To leave this workspace, hand its ownership over to another member first.');
    })->with(['an editor' => 'editor', 'a viewer' => 'viewer']);
});

describe('the markup', function (): void {
    it('escapes the name of the workspace', function (): void {
        $user = inventoryPerson();
        $workspace = app(Workspaces::class)->create($user, '<script>alert("x")</script> & "co"');

        $page = $this->actingAs($user->fresh())->get(membersPageUri($workspace))->assertOk();

        expect($page->getContent())->not->toContain('<script>alert("x")</script>')
            ->and($page->getContent())->toContain('data-workspace-name="&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &quot;co&quot;"');
    });

    it('hands the scripts the messages they show', function (): void {
        $team = inventoryTeam();

        $messages = membersPageMessages($this->actingAs($team['owner'])->get(membersPageUri($team['workspace']))->assertOk());

        expect($messages)->toHaveKeys([
            'workspaces.role.owner', 'workspaces.role.editor', 'workspaces.role.viewer',
            'members.joined', 'members.role_of', 'members.make_owner', 'members.make_owner_named', 'members.remove', 'members.remove_named',
            'members.confirm_remove', 'members.confirm_transfer', 'members.confirm_leave', 'members.confirm_withdraw',
            'members.resend_named', 'members.withdraw_named',
            'members.notice.role_changed', 'members.notice.removed', 'members.notice.transferred', 'members.notice.left',
            'members.notice.invited', 'members.notice.resent', 'members.notice.withdrawn',
            'members.error.not_owner', 'members.error.already_member', 'members.error.already_invited',
            'members.error.cannot_change_owner_role', 'members.error.owner_cannot_leave',
            'members.error.already_owner', 'members.error.member_limit', 'members.error.membership_limit',
            'members.error.resend_too_soon', 'members.error.resend_wait.one', 'members.error.resend_wait.other',
            'invitations.expires', 'invitations.invited_by',
        ]);
    });

    it('is translated', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(membersPageUri($team['workspace']), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Membres de « Shop »')
            ->assertSee('Inviter quelqu&#039;un', false)
            ->assertSee('Envoyer l&#039;invitation', false)
            ->assertSee('Retour à « Shop »');

        $this->flushSession();
        app('auth')->forgetGuards();

        $this->actingAs($team['viewer'])->get(membersPageUri($team['workspace']), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Quitter cet espace de travail');
    });
});

describe('the ways to the page', function (): void {
    it('is linked from the page of the workspace, to every member', function (string $who): void {
        $team = inventoryTeam();

        $this->actingAs($team[$who])->get(route('app.workspace', ['workspace' => $team['workspace']->ulid]))
            ->assertOk()
            ->assertSee('href="'.membersPageUri($team['workspace']).'"', false);
    })->with(['the owner' => 'owner', 'an editor' => 'editor', 'a viewer' => 'viewer']);

    it('is linked from the list of workspaces, with the address its script fills in for each', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();

        $this->actingAs($user)->get(route('app.workspaces'))
            ->assertOk()
            ->assertSee('data-members-url="/app/w/__id__/members"', false)
            ->assertSee(':href="membersUrl(item)"', false);
    });
});
