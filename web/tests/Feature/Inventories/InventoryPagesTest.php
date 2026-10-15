<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Inventories\Fields\FieldTypes;
use App\Models\User;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/**
 * The value of an attribute of the markup, decoded like the browser decodes it.
 */
function pageAttribute(TestResponse $page, string $name): ?string
{
    if (preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $page->getContent(), $match) !== 1) {
        return null;
    }

    return html_entity_decode($match[1], ENT_QUOTES);
}

/**
 * The route of the page of a workspace, of an inventory, or of its fields.
 */
function workspacePage(object $workspace): string
{
    return route('app.workspace', ['workspace' => $workspace->ulid]);
}

function inventoryPage(object $workspace, object $inventory): string
{
    return route('app.inventory', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid]);
}

function fieldsPage(object $workspace, object $inventory): string
{
    return route('app.inventory.fields', ['workspace' => $workspace->ulid, 'inventory' => $inventory->ulid]);
}

describe('/app', function (): void {
    it('sends somebody who is signed in to the page of the workspace they work in', function (): void {
        $user = inventoryPerson();
        $shop = app(Workspaces::class)->create($user, 'Shop');

        // A new workspace becomes the current one.
        $this->actingAs($user->fresh())->get(route('app'))->assertRedirect(workspacePage($shop));
    });

    it('follows the workspace that was chosen', function (): void {
        $user = inventoryPerson();
        $personal = $user->workspaces()->orderBy('workspace_user.id')->firstOrFail();
        app(Workspaces::class)->create($user, 'Shop');
        app(Workspaces::class)->makeCurrent($user->fresh(), $personal);

        $this->actingAs($user->fresh())->get(route('app'))->assertRedirect(workspacePage($personal));
    });

    it('gives an account that has no workspace its personal one, and sends it there', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('app'));

        $personal = $user->workspaces()->firstOrFail();

        $response->assertRedirect(workspacePage($personal));
        expect($user->workspaces()->count())->toBe(1);
    });

    it('sends a visitor to the login page', function (): void {
        $this->get(route('app'))->assertRedirect(route('login'));
    });

    it('sends somebody who is signed in but has no workspace to the list of workspaces, never to the login page (which would send them back here)', function (): void {
        // The account is deleted while the request runs: there is nobody to give a personal workspace to.
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->delete();

        $this->get(route('app'))->assertRedirect(route('app.workspaces'));
    });

    it('is still named app and lives at /app', function (): void {
        expect(route('app', absolute: false))->toBe('/app');
    });
});

describe('the page of a workspace', function (): void {
    it('is shown to every member, with the endpoint its script needs and what the role allows', function (string $role, string $canCreate): void {
        $team = inventoryTeam();
        $page = $this->actingAs($team[$role])->get(workspacePage($team['workspace']), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="workspaceInventories"', false)
            ->assertSee('data-url="/api/v1/workspaces/'.$team['workspace']->ulid.'/inventories"', false)
            ->assertSee('data-inventory-url="/app/w/'.$team['workspace']->ulid.'/inventories/__id__"', false)
            ->assertSee('data-can-create="'.$canCreate.'"', false)
            ->assertSee('<h1 class="page-title">Shop</h1>', false)
            ->assertSee('No inventory yet')
            ->assertSee('New inventory');

        expect(pageAttribute($page, 'data-can-create'))->toBe($canCreate);
    })->with([
        'an owner' => ['owner', '1'],
        'an editor' => ['editor', '1'],
        'a viewer' => ['viewer', '0'],
    ]);

    it('answers 404 to somebody who does not belong to the workspace', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['stranger'])->get(workspacePage($team['workspace']))->assertNotFound();
    });

    it('answers 404 to a workspace that does not exist', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get('/app/w/01h00000000000000000000000')->assertNotFound();
        $this->actingAs($team['owner'])->get('/app/w/not-a-workspace')->assertNotFound();
    });

    it('does not tell a stranger that the workspace exists: the answer is the same as for one that does not', function (): void {
        $team = inventoryTeam();

        $missing = $this->actingAs($team['stranger'])->get('/app/w/01h00000000000000000000000');
        $existing = $this->actingAs($team['stranger'])->get(workspacePage($team['workspace']));

        expect($existing->getStatusCode())->toBe($missing->getStatusCode())
            ->and($existing->getContent())->not->toContain('Shop');
    });

    it('sends a visitor to the login page', function (): void {
        $team = inventoryTeam();

        $this->get(workspacePage($team['workspace']))->assertRedirect(route('login'));
    });

    it('escapes the name of the workspace', function (): void {
        $user = inventoryPerson();
        $workspace = app(Workspaces::class)->create($user, '<script>alert("x")</script>');

        $this->actingAs($user->fresh())->get(workspacePage($workspace))->assertOk()
            ->assertDontSee('<script>alert("x")</script>', false);
    });

    it('is translated', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(workspacePage($team['workspace']), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Inventaires')
            ->assertSee('Nouvel inventaire')
            ->assertSee('Aucun inventaire pour');
    });
});

describe('the page of an inventory', function (): void {
    it('is shown to every member, with the endpoints its scripts need and what the role allows', function (string $role, array $abilities): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $ulid = $inventory->ulid;
        $workspace = $team['workspace']->ulid;

        $page = $this->actingAs($team[$role])->get(inventoryPage($team['workspace'], $inventory), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="inventoryHeader"', false)
            ->assertSee('x-data="inventoryItems"', false)
            ->assertSee('data-url="/api/v1/inventories/'.$ulid.'"', false)
            ->assertSee('data-items-url="/api/v1/inventories/'.$ulid.'/items"', false)
            ->assertSee('data-item-url="/api/v1/inventories/'.$ulid.'/items/__id__"', false)
            ->assertSee('data-workspace-page="/app/w/'.$workspace.'"', false)
            ->assertSee('data-name="Tools"', false);

        expect(pageAttribute($page, 'data-can-update'))->toBe($abilities['update'])
            ->and(pageAttribute($page, 'data-can-delete'))->toBe($abilities['delete'])
            ->and(pageAttribute($page, 'data-can-edit'))->toBe($abilities['edit'])
            ->and(substr_count($page->getContent(), 'data-can-manage-fields="'.$abilities['fields'].'"'))->toBe(2);
    })->with([
        'an owner' => ['owner', ['update' => '1', 'delete' => '1', 'edit' => '1', 'fields' => '1']],
        'an editor' => ['editor', ['update' => '1', 'delete' => '0', 'edit' => '1', 'fields' => '1']],
        'a viewer' => ['viewer', ['update' => '0', 'delete' => '0', 'edit' => '0', 'fields' => '0']],
    ]);

    it('links back to the workspace, whose name is escaped', function (): void {
        $user = inventoryPerson();
        $workspace = app(Workspaces::class)->create($user, 'R&D <b>lab</b>');
        $inventory = inventoryIn($workspace);

        $this->actingAs($user->fresh())->get(inventoryPage($workspace, $inventory))->assertOk()
            ->assertSee('<a href="/app/w/'.$workspace->ulid.'">R&amp;D &lt;b&gt;lab&lt;/b&gt;</a>', false)
            ->assertDontSee('<b>lab</b>', false);
    });

    it('escapes the name and the description of the inventory', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], '"><script>alert(1)</script>');
        $inventory->forceFill(['description' => '<img src=x onerror=alert(2)>'])->save();

        $this->actingAs($team['owner'])->get(inventoryPage($team['workspace'], $inventory))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    });

    it('answers 404 to somebody who does not belong to the workspace', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['stranger'])->get(inventoryPage($team['workspace'], $inventory))->assertNotFound();
    });

    it('answers 404 for an inventory of another workspace, even one the person belongs to', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = inventoryIn($other, 'Elsewhere');
        $inventory = inventoryIn($team['workspace'], 'Tools');

        $this->actingAs($team['owner']->fresh())->get(inventoryPage($team['workspace'], $elsewhere))->assertNotFound();
        $this->actingAs($team['owner']->fresh())->get(inventoryPage($other, $inventory))->assertNotFound();
        $this->actingAs($team['owner']->fresh())->get(inventoryPage($other, $elsewhere))->assertOk();
    });

    it('answers 404 for an inventory that does not exist, and for the ULID of a workspace in its place', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get('/app/w/'.$team['workspace']->ulid.'/inventories/01h00000000000000000000000')->assertNotFound();
        $this->actingAs($team['owner'])->get('/app/w/'.$team['workspace']->ulid.'/inventories/'.$team['workspace']->ulid)->assertNotFound();
    });

    it('finds an inventory whatever the case of its ULID, like the API does', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['owner'])
            ->get('/app/w/'.strtoupper($team['workspace']->ulid).'/inventories/'.strtoupper($inventory->ulid))
            ->assertOk();
    });

    it('sends a visitor to the login page', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->get(inventoryPage($team['workspace'], $inventory))->assertRedirect(route('login'));
    });

    it('is translated', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['owner'])->get(inventoryPage($team['workspace'], $inventory), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Ajouter un article')
            ->assertSee('Cet inventaire n&#039;a pas encore de champ', false)
            ->assertSee('Champs');
    });
});

describe('the field editor of an inventory', function (): void {
    it('is shown to the people who may manage the fields', function (string $role): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $ulid = $inventory->ulid;

        $this->actingAs($team[$role])->get(fieldsPage($team['workspace'], $inventory), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="inventoryFields"', false)
            ->assertSee('data-url="/api/v1/inventories/'.$ulid.'"', false)
            ->assertSee('data-fields-url="/api/v1/inventories/'.$ulid.'/fields"', false)
            ->assertSee('data-order-url="/api/v1/inventories/'.$ulid.'/fields/order"', false)
            ->assertSee('data-field-url="/api/v1/inventories/'.$ulid.'/fields/__id__"', false)
            ->assertSee('Add a field');
    })->with(['an owner' => 'owner', 'an editor' => 'editor']);

    it('is refused to a viewer, who belongs to the workspace: a 403', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['viewer'])->get(fieldsPage($team['workspace'], $inventory))->assertForbidden();
    });

    it('answers 404 to somebody who does not belong to the workspace, not 403', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['stranger'])->get(fieldsPage($team['workspace'], $inventory))->assertNotFound();
    });

    it('answers 404 for an inventory of another workspace', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $elsewhere = inventoryIn($other);

        $this->actingAs($team['owner']->fresh())->get(fieldsPage($team['workspace'], $elsewhere))->assertNotFound();
    });

    it('sends a visitor to the login page', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->get(fieldsPage($team['workspace'], $inventory))->assertRedirect(route('login'));
    });

    it('hands the scripts every type of field, named in the language of the page', function (string $language, string $text, string $amount): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $page = $this->actingAs($team['owner'])->get(fieldsPage($team['workspace'], $inventory), ['Accept-Language' => $language])->assertOk();
        $types = json_decode((string) pageAttribute($page, 'data-types'), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($types, 'type'))->toBe(app(FieldTypes::class)->keys())
            ->and(array_column($types, 'label', 'type'))->toMatchArray(['text' => $text, 'money' => $amount]);
    })->with([
        'English' => ['en', 'Text', 'Amount'],
        'French' => ['fr', 'Texte', 'Montant'],
    ]);

    it('hands the scripts the currencies, named in the language of the page', function (string $language, string $euro): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $page = $this->actingAs($team['owner'])->get(fieldsPage($team['workspace'], $inventory), ['Accept-Language' => $language])->assertOk();
        $currencies = json_decode((string) pageAttribute($page, 'data-currencies'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($currencies, 'name', 'code');

        expect($names)->toHaveKey('USD')
            ->and($names['EUR'])->toBe($euro)
            ->and($names)->not->toHaveKey('XXX')
            ->and($currencies[0])->toHaveKeys(['code', 'name']);
    })->with([
        'English' => ['en', 'Euro'],
        'French' => ['fr', 'euro'],
    ]);

    it('lists, besides the currencies in use, the ones the amount fields of the inventory already have', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);
        $other = inventoryIn($team['workspace'], 'Other');
        fieldIn($inventory, 'money', 'Old price', ['currency' => 'ADP']);
        fieldIn($inventory, 'money', 'Price');
        fieldIn($other, 'money', 'Older price', ['currency' => 'FRF']);

        $named = fn ($page): array => array_column(json_decode((string) pageAttribute($page, 'data-currencies'), true, flags: JSON_THROW_ON_ERROR), 'name', 'code');
        $here = $named($this->actingAs($team['owner'])->get(fieldsPage($team['workspace'], $inventory), ['Accept-Language' => 'en'])->assertOk());
        $there = $named($this->actingAs($team['owner'])->get(fieldsPage($team['workspace'], inventoryIn($team['workspace'], 'Empty')), ['Accept-Language' => 'en'])->assertOk());

        expect($here)->toHaveKey('ADP')->and($here['ADP'])->toBe('Andorran Peseta')
            ->and($here)->not->toHaveKey('FRF')
            ->and($there)->not->toHaveKey('ADP')->not->toHaveKey('FRF')
            ->and($there)->toHaveKeys(['EUR', 'USD', 'JPY']);
    });

    it('is translated', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $this->actingAs($team['owner'])->get(fieldsPage($team['workspace'], $inventory), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Ajouter un champ')
            ->assertSee('Retour aux articles');
    });
});

describe('the workspace switcher on the pages of a workspace', function (): void {
    /**
     * The workspaces the switcher hands to the scripts, read from the page.
     *
     * @return array<string, bool> Name to whether it is marked as the current one.
     */
    function switcherMarks(TestResponse $page): array
    {
        $items = json_decode((string) pageAttribute($page, 'data-workspaces'), true, flags: JSON_THROW_ON_ERROR);

        return array_column($items, 'current', 'name');
    }

    it('marks the workspace of the page, whichever one the account has chosen', function (): void {
        $user = inventoryPerson();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        $garage = app(Workspaces::class)->create($user->fresh(), 'Garage');
        $inventory = inventoryIn($shop);

        // The account works in the Garage (the last one it created).
        $this->actingAs($user->fresh())->get(route('app.workspaces'))
            ->assertSee('data-workspaces=', false);
        expect(switcherMarks($this->actingAs($user->fresh())->get(route('app.workspaces'))))
            ->toBe(['Espace personnel' => false, 'Shop' => false, 'Garage' => true]);

        expect(switcherMarks($this->actingAs($user->fresh())->get(workspacePage($shop))))
            ->toBe(['Espace personnel' => false, 'Shop' => true, 'Garage' => false])
            ->and(switcherMarks($this->actingAs($user->fresh())->get(inventoryPage($shop, $inventory))))
            ->toBe(['Espace personnel' => false, 'Shop' => true, 'Garage' => false])
            ->and(switcherMarks($this->actingAs($user->fresh())->get(fieldsPage($shop, $inventory))))
            ->toBe(['Espace personnel' => false, 'Shop' => true, 'Garage' => false])
            ->and(switcherMarks($this->actingAs($user->fresh())->get(workspacePage($garage))))
            ->toBe(['Espace personnel' => false, 'Shop' => false, 'Garage' => true]);
    });

    it('names the workspace of the page in the button, and gives the scripts the page to go to', function (): void {
        $user = inventoryPerson();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        app(Workspaces::class)->create($user->fresh(), 'Garage');

        $this->actingAs($user->fresh())->get(workspacePage($shop), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-text="currentName">Shop</span>', false)
            ->assertSee('data-workspace-url="/app/w/__id__"', false);
    });

    it('leaves the choice of the account alone: looking at a workspace does not make it the current one', function (): void {
        $user = inventoryPerson();
        $shop = app(Workspaces::class)->create($user, 'Shop');
        $garage = app(Workspaces::class)->create($user->fresh(), 'Garage');

        $this->actingAs($user->fresh())->get(workspacePage($shop))->assertOk();

        $this->actingAs($user->fresh())->get(route('app'))->assertRedirect(workspacePage($garage));
    });
});

describe('the pages of a workspace', function (): void {
    it('check that the session was not revoked, like the other pages of the space', function (string $route): void {
        $middleware = Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class);
    })->with(['app', 'app.workspace', 'app.inventory', 'app.inventory.fields']);

    it('are at the paths the links use', function (): void {
        expect(route('app.workspace', ['workspace' => 'ws'], absolute: false))->toBe('/app/w/ws')
            ->and(route('app.inventory', ['workspace' => 'ws', 'inventory' => 'inv'], absolute: false))->toBe('/app/w/ws/inventories/inv')
            ->and(route('app.inventory.fields', ['workspace' => 'ws', 'inventory' => 'inv'], absolute: false))->toBe('/app/w/ws/inventories/inv/fields');
    });

    it('leave out nothing a viewer of the workspace is allowed to see', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $viewer = $team['viewer'];

        expect(app(Workspaces::class)->roleOf($viewer, $team['workspace']))->toBe(WorkspaceRole::Viewer);

        $this->actingAs($viewer)->get(workspacePage($team['workspace']))->assertOk();
        $this->actingAs($viewer)->get(inventoryPage($team['workspace'], $inventory))->assertOk();
    });
});
