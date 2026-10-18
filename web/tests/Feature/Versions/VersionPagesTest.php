<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Models\Inventory;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/versions.php';

/*
 * The page of the official versions of an inventory (`/app/w/{workspace}/inventories/{inventory}/versions`) and the way into it from the
 * page of the inventory: who sees them, what the page hands its script, and that it is the page of the workspace it belongs to.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
    config(['services.documenso.url' => null, 'services.documenso.api_key' => null, 'services.documenso.webhook_secret' => null]);
});

/**
 * The address of the page of the versions of an inventory.
 */
function versionsPage(Workspace $workspace, Inventory $inventory): string
{
    return route('app.inventory.versions', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid]);
}

/**
 * A `data-*` attribute of the markup of a page, decoded as the browser decodes it; null when the page has none.
 */
function versionsPageAttribute(TestResponse $page, string $name): ?string
{
    if (preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $page->getContent(), $match) !== 1) {
        return null;
    }

    return html_entity_decode($match[1], ENT_QUOTES);
}

describe('the page of the official versions', function (): void {
    it('is at the path the links use', function (): void {
        expect(route('app.inventory.versions', ['workspace' => 'ws', 'inventory' => 'inv'], absolute: false))->toBe('/app/w/ws/inventories/inv/versions');
    });

    it('checks that the session was not revoked, like the other pages of the space', function (): void {
        $middleware = Route::getRoutes()->getByName('app.inventory.versions')?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class);
    });

    it('is shown to every member, with the endpoints its script needs and what the role allows', function (string $role, string $canCreate): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $ulid = $inventory->ulid;

        $page = $this->actingAs($team[$role])->get(versionsPage($team['workspace'], $inventory), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="inventoryVersions"', false)
            ->assertSee('data-url="/api/v1/inventories/'.$ulid.'/versions"', false)
            ->assertSee('data-version-url="/api/v1/inventories/'.$ulid.'/versions/__id__"', false)
            ->assertSee('data-cancel-url="/api/v1/inventories/'.$ulid.'/versions/__id__/signature/cancel"', false)
            ->assertSee('data-retry-url="/api/v1/inventories/'.$ulid.'/versions/__id__/signature/retry"', false)
            ->assertSee('Official versions')
            ->assertSee('No official version yet');

        expect(versionsPageAttribute($page, 'data-can-create'))->toBe($canCreate);
    })->with([
        'an owner' => ['owner', '1'],
        'an editor' => ['editor', '1'],
        'a viewer' => ['viewer', '0'],
    ]);

    it('hands the script whether the instance can ask for signatures, and how many signers a version takes', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $without = $this->actingAs($team['owner'])->get(versionsPage($team['workspace'], $inventory))->assertOk();

        expect(versionsPageAttribute($without, 'data-signatures-available'))->toBe('0')
            ->and(versionsPageAttribute($without, 'data-max-signers'))->toBe('10');

        versionDocumensoConfig();
        config(['inventories.max_signers' => 4]);

        $with = $this->actingAs($team['owner'])->get(versionsPage($team['workspace'], $inventory))->assertOk();

        expect(versionsPageAttribute($with, 'data-signatures-available'))->toBe('1')
            ->and(versionsPageAttribute($with, 'data-max-signers'))->toBe('4');
    });

    it('never hands the script more signers than the page of signatures holds', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        config(['inventories.max_signers' => 50]);

        $page = $this->actingAs($team['owner'])->get(versionsPage($team['workspace'], $inventory))->assertOk();

        expect(versionsPageAttribute($page, 'data-max-signers'))->toBe('10');
    });

    it('does not hand the script the address or the token of the signature service', function (): void {
        versionDocumensoConfig();
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(versionsPage($team['workspace'], inventoryIn($team['workspace'])))->assertOk()
            ->assertDontSee('sign.example.test')
            ->assertDontSee('api_test_key')
            ->assertDontSee('secret-test');
    });

    it('links back to the workspace and to the inventory, whose names are escaped', function (): void {
        $user = inventoryPerson();
        $workspace = app(Workspaces::class)->create($user, 'R&D <b>lab</b>');
        $inventory = inventoryIn($workspace, '"><script>alert(1)</script>');

        $this->actingAs($user->fresh())->get(versionsPage($workspace, $inventory))->assertOk()
            ->assertSee('<a href="/app/w/'.$workspace->ulid.'">R&amp;D &lt;b&gt;lab&lt;/b&gt;</a>', false)
            ->assertSee('href="/app/w/'.$workspace->ulid.'/inventories/'.$inventory->ulid.'"', false)
            ->assertDontSee('<b>lab</b>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    });

    it('answers 404 to somebody who does not belong to the workspace', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['stranger'])->get(versionsPage($team['workspace'], $inventory))->assertNotFound();
    });

    it('does not tell a stranger that the inventory exists: the answer is the same as for one that does not', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Secret tools');

        $missing = $this->actingAs($team['stranger'])->get('/app/w/'.$team['workspace']->ulid.'/inventories/01h00000000000000000000000/versions');
        $existing = $this->actingAs($team['stranger'])->get(versionsPage($team['workspace'], $inventory));

        expect($existing->getStatusCode())->toBe($missing->getStatusCode())
            ->and($existing->getContent())->not->toContain('Secret tools');
    });

    it('answers 404 for an inventory of another workspace, even one the person belongs to', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = inventoryIn($other, 'Elsewhere');
        $inventory = inventoryIn($team['workspace'], 'Tools');

        $this->actingAs($team['owner']->fresh())->get(versionsPage($team['workspace'], $elsewhere))->assertNotFound();
        $this->actingAs($team['owner']->fresh())->get(versionsPage($other, $inventory))->assertNotFound();
        $this->actingAs($team['owner']->fresh())->get(versionsPage($other, $elsewhere))->assertOk();
    });

    it('answers 404 for an inventory that does not exist, and for the ULID of a workspace in its place', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get('/app/w/'.$team['workspace']->ulid.'/inventories/01h00000000000000000000000/versions')->assertNotFound();
        $this->actingAs($team['owner'])->get('/app/w/'.$team['workspace']->ulid.'/inventories/'.$team['workspace']->ulid.'/versions')->assertNotFound();
    });

    it('sends a visitor to the login page', function (): void {
        $team = inventoryTeam();

        $this->get(versionsPage($team['workspace'], inventoryIn($team['workspace'])))->assertRedirect(route('login'));
    });

    it('makes its workspace the one the account works in, like the other pages of a workspace', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $inventory = inventoryIn($team['workspace']);

        // The owner just created "Other": it is the current one until they open a page of "Shop".
        expect($team['owner']->fresh()->current_workspace_id)->toBe($other->id);

        $this->actingAs($team['owner']->fresh())->get(versionsPage($team['workspace'], $inventory))->assertOk();

        expect($team['owner']->fresh()->current_workspace_id)->toBe($team['workspace']->id);
    });

    it('writes nothing for a page that is refused', function (): void {
        $team = inventoryTeam();
        $before = $team['stranger']->fresh()->current_workspace_id;

        $this->actingAs($team['stranger'])->get(versionsPage($team['workspace'], inventoryIn($team['workspace'])))->assertNotFound();

        expect($team['stranger']->fresh()->current_workspace_id)->toBe($before);
    });

    it('is translated', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(versionsPage($team['workspace'], inventoryIn($team['workspace'])), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Versions officielles')
            ->assertSee('Nouvelle version')
            ->assertSee('Aucune version officielle pour le moment')
            ->assertSee('Demander des signatures')
            ->assertSee('Retour aux articles');
    });

    it('gives the scripts the words they need in the page, in the language of the person', function (): void {
        $team = inventoryTeam();

        $page = $this->actingAs($team['owner'])->get(versionsPage($team['workspace'], inventoryIn($team['workspace'])), ['Accept-Language' => 'fr'])->assertOk();

        $page->assertSee('versions.status.completed', false)
            ->assertSee('versions.signature.status.awaiting', false)
            ->assertSee('versions.confirm.delete', false)
            ->assertSee('PDF prêt', false);
    });

    it('is not indexed by search engines, like every page of the space', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(versionsPage($team['workspace'], inventoryIn($team['workspace'])))->assertOk()
            ->assertSee('noindex', false);
    });
});

describe('the way into the versions from the page of an inventory', function (): void {
    it('is a link in the header, for every role', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $href = '/app/w/'.$team['workspace']->ulid.'/inventories/'.$inventory->ulid.'/versions';

        $this->actingAs($team[$role])->get(route('app.inventory', ['workspace' => $team['workspace']->ulid, 'inventory' => $inventory->ulid]), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('<a class="btn" href="'.$href.'">', false)
            ->assertSee('Official versions');
    })->with(['owner', 'editor', 'viewer']);

    it('is translated', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['viewer'])->get(route('app.inventory', ['workspace' => $team['workspace']->ulid, 'inventory' => $inventory->ulid]), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Versions officielles');
    });
});
