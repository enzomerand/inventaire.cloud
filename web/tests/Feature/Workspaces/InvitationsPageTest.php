<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The page of the invitations addressed to the signed-in person (`/app/invitations`), which the invitation mail links to, and the link to
 * it in the header of the app. The list and the answers go through the API (InvitationsApiTest holds the rules); the page frames them.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * An invitation to an address, as the table holds it (nothing is mailed).
 *
 * @param  array<string, mixed>  $attributes
 */
function invitationsPageFor(string $email, ?Workspace $workspace = null, array $attributes = []): WorkspaceInvitation
{
    $workspace ??= app(Workspaces::class)->create(inventoryPerson('Owner'), 'Shop');

    return WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'email' => $email] + $attributes);
}

/**
 * A person whose address is given, with a personal workspace.
 */
function invitationsPagePerson(string $email = 'guest@example.test'): User
{
    return User::factory()->withPersonalWorkspace()->create(['name' => 'Guest', 'email' => $email]);
}

/**
 * How many queries on the invitations table a request ran, and its response.
 *
 * @return array{0: TestResponse, 1: int}
 */
function invitationsPageQueries(Closure $request): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $request();
    $queries = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains((string) $entry['query'], 'workspace_invitations'))->count();

    DB::disableQueryLog();

    return [$response, $queries];
}

describe('the page', function (): void {
    it('is at exactly /app/invitations, the path the invitation mail links to, behind the same checks as the other pages of the space', function (): void {
        expect(route('app.invitations', absolute: false))->toBe('/app/invitations')
            // The mail builds its link from the constant (a route needs a request, which the service does not always run in).
            ->and(WorkspaceInvitationMail::PAGE)->toBe(route('app.invitations', absolute: false));

        $middleware = Route::getRoutes()->getByName('app.invitations')?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class);
    });

    it('sends a visitor to the login page, and keeps the page they were going to', function (): void {
        $this->get('/app/invitations')->assertRedirect(route('login'));

        expect(session('url.intended'))->toBe('http://localhost/app/invitations');

        // Once signed in, the login page sends the person to /app, which takes them back to where they were going.
        $person = invitationsPagePerson();

        $this->actingAs($person)->get('/app')->assertRedirect('http://localhost/app/invitations');
        $this->get('/app')->assertRedirect(route('app.workspace', ['workspace' => $person->workspaces()->sole()->ulid]));
    });

    it('is shown to somebody who is signed in, with the endpoints its script needs', function (): void {
        $this->actingAs(invitationsPagePerson())->get('/app/invitations')
            ->assertOk()
            ->assertSee('x-data="myInvitations"', false)
            ->assertSee('data-url="/api/v1/me/invitations"', false)
            ->assertSee('data-workspace-url="/app/w/__id__"', false)
            ->assertSee('Invitations')
            ->assertSee('No invitation is waiting for you');
    });

    it('holds no invitation itself: the script reads them from the API, for the address of the person', function (): void {
        invitationsPageFor('guest@example.test');

        $this->actingAs(invitationsPagePerson())->get('/app/invitations')
            ->assertOk()
            ->assertDontSee('Shop');
    });

    it('hands the scripts the messages they show', function (): void {
        $page = $this->actingAs(invitationsPagePerson())->get('/app/invitations')->assertOk();
        preg_match('#<script type="application/json" id="client-messages">(.*?)</script>#s', $page->getContent(), $match);

        expect(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR))->toHaveKeys([
            'workspaces.role.editor', 'workspaces.role.viewer',
            'invitations.expires', 'invitations.invited_by', 'invitations.accept_named', 'invitations.decline_named',
            'invitations.confirm_decline', 'invitations.notice.accepted', 'invitations.notice.declined',
            'invitations.error.workspace_full', 'invitations.error.membership_limit',
            'invitations.waiting.one', 'invitations.waiting.other',
            'members.error.member_limit', 'members.error.membership_limit',
        ]);
    });

    it('is translated', function (): void {
        $this->actingAs(invitationsPagePerson())->get('/app/invitations', ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Aucune invitation ne vous attend')
            ->assertSee('Accepter')
            ->assertSee('Refuser');
    });
});

describe('the link to it in the header of the app', function (): void {
    it('is not there while no invitation waits', function (): void {
        $this->actingAs(invitationsPagePerson())->get(route('app.account'))
            ->assertOk()
            ->assertDontSee('x-data="invitationsLink"', false);
    });

    it('is there, with how many wait, on the pages of the app', function (string $path): void {
        invitationsPageFor('guest@example.test');
        invitationsPageFor('guest@example.test');

        $this->actingAs(invitationsPagePerson())->get($path)
            ->assertOk()
            ->assertSee('x-data="invitationsLink"', false)
            ->assertSee('data-count="2"', false)
            ->assertSee('href="/app/invitations"', false)
            ->assertSee('aria-label="2 invitations waiting"', false)
            ->assertSee('<span class="badge bg-red text-red-fg" x-text="count">2</span>', false);
    })->with(['the account' => '/app/account', 'the workspaces' => '/app/workspaces', 'the invitations' => '/app/invitations']);

    it('says "1 invitation waiting" for one', function (): void {
        invitationsPageFor('guest@example.test');

        $this->actingAs(invitationsPagePerson())->get('/app/account')
            ->assertOk()
            ->assertSee('data-count="1"', false)
            ->assertSee('aria-label="1 invitation waiting"', false);
    });

    it('is on the pages of a workspace too', function (): void {
        $team = inventoryTeam();
        invitationsPageFor($team['viewer']->email);

        $this->actingAs($team['viewer'])->get(route('app.workspace', ['workspace' => $team['workspace']->ulid]))
            ->assertOk()
            ->assertSee('x-data="invitationsLink"', false);
    });

    it('is translated', function (): void {
        invitationsPageFor('guest@example.test');
        invitationsPageFor('guest@example.test');

        $this->actingAs(invitationsPagePerson())->get('/app/account', ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('aria-label="2 invitations en attente"', false);
    });

    it('counts the invitations to the address of the person, whatever its case, and nobody else\'s', function (): void {
        invitationsPageFor('guest@example.test');
        invitationsPageFor('somebody.else@example.test');

        $this->actingAs(invitationsPagePerson('Guest@Example.TEST'))->get('/app/account')
            ->assertOk()
            ->assertSee('data-count="1"', false);
    });

    it('does not count an invitation that has lapsed', function (): void {
        invitationsPageFor('guest@example.test', attributes: ['expires_at' => now()->subSecond()]);

        $this->actingAs(invitationsPagePerson())->get('/app/account')
            ->assertOk()
            ->assertDontSee('x-data="invitationsLink"', false);
    });

    it('stops showing once the invitations have been answered', function (): void {
        $invitation = invitationsPageFor('guest@example.test');
        $person = invitationsPagePerson();

        $this->actingAs($person)->get('/app/account')->assertSee('x-data="invitationsLink"', false);

        $invitation->delete();

        $this->actingAs($person)->get('/app/account')->assertDontSee('x-data="invitationsLink"', false);
    });

    it('costs one query on the invitations on a page of the app, and none on a public page', function (): void {
        invitationsPageFor('guest@example.test');
        $person = invitationsPagePerson();

        [$app, $onApp] = invitationsPageQueries(fn () => $this->actingAs($person)->get('/app/account'));
        $app->assertOk();

        expect($onApp)->toBe(1);

        foreach (['/', '/templates', '/docs/api'] as $path) {
            [$public, $onPublic] = invitationsPageQueries(fn () => $this->actingAs($person)->get($path));

            expect($onPublic)->toBe(0, "a query on the invitations was run for {$path}");
            $public->assertOk()->assertDontSee('x-data="invitationsLink"', false);
        }
    });

    it('is not shown to a visitor, nor on the login page', function (): void {
        invitationsPageFor('guest@example.test');

        $this->get('/')->assertOk()->assertDontSee('x-data="invitationsLink"', false);
        $this->get(route('login'))->assertOk()->assertDontSee('x-data="invitationsLink"', false);
    });
});
