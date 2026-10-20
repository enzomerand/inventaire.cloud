<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Models\Inventory;
use App\Models\Workspace;
use App\Settings\InstanceSettings;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/sharing.php';

uses(RefreshDatabase::class);

/*
 * The sharing page of an inventory (`/app/w/{workspace}/inventories/{inventory}/sharing`), where the owners make, copy and revoke share
 * links, and the way into it from the page of the inventory: who sees what, and what the page hands its script.
 */

/**
 * The address of the sharing page of an inventory.
 */
function sharingPage(Workspace $workspace, Inventory $inventory): string
{
    return route('app.inventory.sharing', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid]);
}

/**
 * A `data-*` attribute of the markup of a page, decoded as the browser decodes it; null when the page has none.
 */
function sharingPageAttribute(TestResponse $page, string $name): ?string
{
    if (preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $page->getContent(), $match) !== 1) {
        return null;
    }

    return html_entity_decode($match[1], ENT_QUOTES);
}

describe('the sharing page', function (): void {
    it('is at the path the links use', function (): void {
        expect(route('app.inventory.sharing', ['workspace' => 'ws', 'inventory' => 'inv'], absolute: false))->toBe('/app/w/ws/inventories/inv/sharing');
    });

    it('checks that the session was not revoked, like the other pages of the space', function (): void {
        $middleware = Route::getRoutes()->getByName('app.inventory.sharing')?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class);
    });

    it('is shown to an owner, with the endpoint its script needs and what the instance allows', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');

        $page = $this->actingAs($team['owner'])->get(sharingPage($team['workspace'], $inventory), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="inventorySharing"', false)
            ->assertSee('data-url="/api/v1/inventories/'.$inventory->ulid.'/share-links"', false)
            ->assertSee('<h1 class="page-title" tabindex="-1" data-focus="heading">Sharing</h1>', false)
            ->assertSee('Create a share link')
            ->assertSee('Show the photos and files')
            ->assertSee('Revoke');

        expect(sharingPageAttribute($page, 'data-enabled'))->toBe('1')
            ->and(sharingPageAttribute($page, 'data-limit'))->toBe((string) config('inventories.max_share_links'))
            ->and(shareTexts(shareDom($page), '//select[@id="share-link-expiry"]/option'))->toBe(['7 days', '30 days', '90 days', '365 days', 'Never']);
    });

    it('offers the lifetimes the API takes, with 30 days chosen first by the script', function (): void {
        $team = inventoryTeam();
        $page = shareDom($this->actingAs($team['owner'])->get(sharingPage($team['workspace'], inventoryIn($team['workspace'])))->assertOk());

        expect(shareAttributes($page, '//select[@id="share-link-expiry"]/option', 'value'))->toBe(['7', '30', '90', '365', ''])
            ->and(shareAttributes($page, '//input[@id="share-link-label"]', 'maxlength'))->toBe(['80']);
    });

    it('tells the script that sharing is turned off on the instance, and says so', function (): void {
        $team = inventoryTeam();
        app(InstanceSettings::class)->update([InstanceSettings::SHARE_LINKS_ENABLED => false]);

        $page = $this->actingAs($team['owner'])->get(sharingPage($team['workspace'], inventoryIn($team['workspace'])), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('Sharing by link is turned off on this instance.');

        expect(sharingPageAttribute($page, 'data-enabled'))->toBe('0');
    });

    it('links back to the items and names the workspace and the inventory, escaped', function (): void {
        $user = inventoryPerson();
        $workspace = app(Workspaces::class)->create($user, 'R&D <b>lab</b>');
        $inventory = inventoryIn($workspace, 'Tools <i>x</i>');

        $this->actingAs($user->fresh())->get(sharingPage($workspace, $inventory))->assertOk()
            ->assertSee('<a href="/app/w/'.$workspace->ulid.'">R&amp;D &lt;b&gt;lab&lt;/b&gt;</a>', false)
            ->assertSee('<a href="/app/w/'.$workspace->ulid.'/inventories/'.$inventory->ulid.'">Tools &lt;i&gt;x&lt;/i&gt;</a>', false)
            ->assertDontSee('<b>lab</b>', false)
            ->assertDontSee('<i>x</i>', false);
    });

    it('is refused to an editor and a viewer, who belong to the workspace: a 403', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team[$role])->get(sharingPage($team['workspace'], $inventory))->assertForbidden();
    })->with(['an editor' => 'editor', 'a viewer' => 'viewer']);

    it('answers 404 to somebody who does not belong to the workspace, not 403', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['stranger'])->get(sharingPage($team['workspace'], $inventory))->assertNotFound();
    });

    it('answers 404 for an inventory of another workspace, even one the person owns', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = inventoryIn($other);

        $this->actingAs($team['owner']->fresh())->get(sharingPage($team['workspace'], $elsewhere))->assertNotFound();
        $this->actingAs($team['owner']->fresh())->get('/app/w/'.$team['workspace']->ulid.'/inventories/not-an-inventory/sharing')->assertNotFound();
    });

    it('sends a visitor to the login page', function (): void {
        $team = inventoryTeam();

        $this->get(sharingPage($team['workspace'], inventoryIn($team['workspace'])))->assertRedirect(route('login'));
    });

    it('makes the workspace the current one of the account, like the other pages of a workspace', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $inventory = inventoryIn($team['workspace']);

        expect($team['owner']->fresh()->current_workspace_id)->toBe($other->id);

        $this->actingAs($team['owner']->fresh())->get(sharingPage($team['workspace'], $inventory))->assertOk();

        expect($team['owner']->fresh()->current_workspace_id)->toBe($team['workspace']->id);
    });

    it('does not change the current workspace of somebody it refuses', function (): void {
        $team = inventoryTeam();
        $before = $team['viewer']->fresh()->current_workspace_id;

        $this->actingAs($team['viewer'])->get(sharingPage($team['workspace'], inventoryIn($team['workspace'])))->assertForbidden();

        expect($team['viewer']->fresh()->current_workspace_id)->toBe($before);
    });

    it('is translated', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(sharingPage($team['workspace'], inventoryIn($team['workspace'])), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Partage')
            ->assertSee('Créer un lien de partage')
            ->assertSee('Afficher les photos et les fichiers')
            ->assertSee('Cet inventaire n\'a pas encore de lien de partage.');
    });

    it('hands the script nothing secret: the addresses of the links come from the API', function (): void {
        $case = shareLinkCase();
        ['token' => $token] = shareLinkMake($case['inventory'], $case['owner']);

        $this->actingAs($case['owner'])->get(sharingPage($case['team']['workspace'], $case['inventory']))->assertOk()
            ->assertDontSee($token);
    });
});

describe('the way into the sharing page', function (): void {
    it('is on the page of the inventory for an owner, who is the only one to see it', function (string $role, bool $shown): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $address = '/app/w/'.$team['workspace']->ulid.'/inventories/'.$inventory->ulid.'/sharing';

        $page = $this->actingAs($team[$role])->get(route('app.inventory', ['workspace' => $team['workspace']->ulid, 'inventory' => $inventory->ulid]), ['Accept-Language' => 'en'])
            ->assertOk();

        if ($shown) {
            $page->assertSee('href="'.$address.'"', false);
            expect(shareTexts(shareDom($page), '//a[@href="'.$address.'"]'))->toBe(['Share']);
        } else {
            $page->assertDontSee('/sharing')->assertDontSee('href="'.$address.'"', false);
        }
    })->with([
        'an owner' => ['owner', true],
        'an editor' => ['editor', false],
        'a viewer' => ['viewer', false],
    ]);

    it('is translated', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $page = $this->actingAs($team['owner'])->get(route('app.inventory', ['workspace' => $team['workspace']->ulid, 'inventory' => $inventory->ulid]), ['Accept-Language' => 'fr'])
            ->assertOk();

        expect(shareTexts(shareDom($page), '//a[contains(@href, "/sharing")]'))->toBe(['Partager']);
    });
});
