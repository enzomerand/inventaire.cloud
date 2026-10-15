<?php

declare(strict_types=1);

use App\Models\User;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The workspaces the switcher hands to the scripts, read from the page.
 *
 * @return list<array{id: string, name: string, role: string, current: bool}>
 */
function switcherItems(TestResponse $page): array
{
    preg_match('/data-workspaces="([^"]*)"/', $page->getContent(), $match);

    return json_decode(html_entity_decode($match[1] ?? '[]', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
}

describe('the workspace switcher of the header', function (): void {
    it('is shown before the account menu, to somebody who is signed in', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();

        $page = $this->actingAs($user)->get(route('app'), ['Accept-Language' => 'en'])->assertOk()
            ->assertSee('x-data="workspaceSwitcher"', false)
            ->assertSee('data-url="/api/v1/me/current-workspace"', false)
            ->assertSee('New workspace')
            ->assertSee('Manage workspaces');

        expect(strpos($page->getContent(), 'x-data="workspaceSwitcher"'))->toBeLessThan(strpos($page->getContent(), 'x-data="accountMenu"'));
    });

    it('is on the public pages too, for somebody who is signed in, and not for a visitor', function (): void {
        $this->get(route('home'))->assertOk()->assertDontSee('workspaceSwitcher');
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route('home'))->assertOk()->assertSee('x-data="workspaceSwitcher"', false);
    });

    it('is not on the install wizard, which has no accounts to look up', function (): void {
        $this->bootInstaller(installed: false);
        $this->get('/');

        $this->get('/install')->assertOk()->assertDontSee('workspaceSwitcher');
    });

    it('lists the workspaces with the current one marked, and names it in the button', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = User::factory()->withPersonalWorkspace()->create();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        $shared = app(Workspaces::class)->create($other, 'Shared');
        $shared->members()->attach($user->id, ['role' => WorkspaceRole::Viewer->value]);
        $personal = $user->workspaces()->orderBy('workspace_user.id')->firstOrFail();

        $page = $this->actingAs($user->fresh())->get(route('app'), ['Accept-Language' => 'en'])->assertOk();

        expect(switcherItems($page))->toBe([
            ['id' => $personal->ulid, 'name' => 'Espace personnel', 'role' => 'owner', 'current' => false],
            ['id' => $shop->ulid, 'name' => 'Shop', 'role' => 'owner', 'current' => true],
            ['id' => $shared->ulid, 'name' => 'Shared', 'role' => 'viewer', 'current' => false],
        ]);
        $page->assertSee('x-text="currentName">Shop</span>', false);
    });

    it('escapes the names of the workspaces', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        app(Workspaces::class)->create($user, '<script>alert("x")</script> & "co"');

        $page = $this->actingAs($user->fresh())->get(route('app'))->assertOk();

        expect($page->getContent())->not->toContain('<script>alert("x")</script>')
            ->and(collect(switcherItems($page))->pluck('name')->all())->toContain('<script>alert("x")</script> & "co"');
    });

    it('still works for an account that has no workspace yet', function (): void {
        $this->actingAs(User::factory()->create())->get(route('app'))
            ->assertOk()
            ->assertSee('data-workspaces="[]"', false);
    });

    it('is translated', function (): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route('app'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Nouvel espace de travail')
            ->assertSee('Gérer les espaces de travail')
            ->assertSee('Changer d&#039;espace de travail', false);
    });
});

describe('the account menu', function (): void {
    it('links to the workspaces and to the account', function (): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route('app'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('href="'.route('app.workspaces').'"', false)
            ->assertSee('href="'.route('app.account').'"', false)
            ->assertSee('My account');
    });
});

describe('the workspaces page', function (): void {
    it('is shown to somebody who is signed in, with the endpoints its script needs', function (): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route('app.workspaces'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="workspaces"', false)
            ->assertSee('data-url="/api/v1/workspaces"', false)
            ->assertSee('data-current-url="/api/v1/me/current-workspace"', false)
            ->assertSee('Your workspaces')
            ->assertSee('Create the workspace')
            ->assertSee('maxlength="100"', false);
    });

    it('is at /app/workspaces and sends a visitor to the login page', function (): void {
        expect(route('app.workspaces', absolute: false))->toBe('/app/workspaces');

        $this->get(route('app.workspaces'))->assertRedirect(route('login'));
    });

    it('is translated', function (): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route('app.workspaces'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Vos espaces de travail')
            ->assertSee('Créer l&#039;espace de travail', false);
    });
});

describe('the account page', function (): void {
    it('is shown to somebody who is signed in, with the endpoints its scripts need', function (): void {
        $user = User::factory()->speaking('en')->withPersonalWorkspace()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->actingAs($user)->get(route('app.account'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="accountProfile"', false)
            ->assertSee('data-url="/api/v1/me"', false)
            ->assertSee('data-name="Ada Lovelace"', false)
            ->assertSee('data-locale="en"', false)
            ->assertSee('x-data="accountSecurity"', false)
            ->assertSee('data-url="/api/v1/me/sign-out-everywhere"', false)
            ->assertSee('x-data="accountData"', false)
            ->assertSee('data-url="/api/v1/me/export"', false)
            ->assertSee('x-data="accountDeletion"', false)
            ->assertSee('data-code-url="/api/v1/me/deletion-code"', false)
            ->assertSee('data-home-url="/"', false)
            ->assertSee('value="ada@example.com" readonly', false)
            ->assertSee('Delete my account')
            ->assertSee('Download my data');
    });

    it('offers every language the application is translated into, the language of the account selected', function (): void {
        $user = User::factory()->speaking('en')->create();

        $page = $this->actingAs($user)->get(route('app.account'), ['Accept-Language' => 'fr'])->assertOk();

        expect($page->getContent())
            ->toMatch('/<option value="fr" lang="fr"\s*>Français<\/option>/')
            ->toMatch('/<option value="en" lang="en" selected>English<\/option>/');
    });

    it('selects the language of the request while the account has none', function (): void {
        $page = $this->actingAs(User::factory()->create())->get(route('app.account'), ['Accept-Language' => 'en'])->assertOk();

        expect($page->getContent())
            ->toMatch('/<option value="en" lang="en" selected>English<\/option>/')
            ->toMatch('/<option value="fr" lang="fr"\s*>Français<\/option>/');
    });

    it('escapes the name of the account', function (): void {
        $user = User::factory()->create(['name' => '"><script>alert(1)</script>']);

        $this->actingAs($user)->get(route('app.account'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    });

    it('sends a visitor to the login page', function (): void {
        $this->get(route('app.account'))->assertRedirect(route('login'));
    });

    it('is translated', function (): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route('app.account'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Supprimer mon compte')
            ->assertSee('Se déconnecter partout')
            ->assertSee('Télécharger mes données');
    });
});

describe('the scripts', function (): void {
    it('get the messages the workspaces and the account pages show', function (): void {
        $page = $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route('app.account'), ['Accept-Language' => 'en'])->assertOk();

        preg_match('#<script type="application/json" id="client-messages">(.*?)</script>#s', $page->getContent(), $match);
        $messages = json_decode($match[1], true);

        expect($messages)->toHaveKeys([
            'workspaces.role.owner', 'workspaces.role.editor', 'workspaces.role.viewer',
            'workspaces.rename_named', 'workspaces.delete_named', 'workspaces.confirm_delete',
            'workspaces.notice.created', 'workspaces.notice.renamed', 'workspaces.notice.deleted', 'workspaces.error.last',
            'account.notice.saved', 'account.notice.signed_out_everywhere', 'account.notice.code_sent', 'account.notice.code_resent',
            'account.error.last_administrator', 'account.error.sole_owner',
        ])
            ->and($messages['workspaces.role.owner'])->toBe('Owner')
            ->and($messages['workspaces.confirm_delete'])->toContain(':name');
    });
});
