<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Models\BillingAccount;
use App\Models\Workspace;
use App\Settings\InstanceSettings;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The pages of the subscription: `/app/w/{workspace}/billing` (the plan of a workspace, for its members), `/app/billing` (which leads to
 * the one of the workspace the person works in), the link to it on the page of the workspace, and the photo entry switch of the
 * workspaces page, which a plan without the photo entry turns off. The page only frames what the API says (BillingApiTest holds the
 * rules and the Vitest tests of the component what it does with it): what is checked here is who may open it and the markup the script
 * relies on.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The address of the subscription page of a workspace.
 */
function billingPageUri(Workspace $workspace): string
{
    return route('app.workspace.billing', ['workspace' => $workspace->ulid], absolute: false);
}

/**
 * The messages the layout hands to the scripts of a page, as the page embeds them.
 *
 * @return array<string, string>
 */
function billingPageMessages(TestResponse $page): array
{
    preg_match('#<script type="application/json" id="client-messages">(.*?)</script>#s', $page->getContent(), $match);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Makes the instance offer the photo entry: the administrator turned it on and there is an API key.
 */
function billingPageOffersPhotoEntry(): void
{
    app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);
    config(['services.anthropic.key' => 'sk-ant-test-secret']);
}

/**
 * The ULIDs the workspaces page says are on a plan without the photo entry, decoded as the browser decodes them.
 *
 * @return list<string>
 */
function billingPageLockedWorkspaces(TestResponse $page): array
{
    preg_match('/data-photo-entry-locked="([^"]*)"/', $page->getContent(), $match);

    expect($match)->not->toBe([], 'the page has a data-photo-entry-locked attribute');

    return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
}

describe('the subscription page of a workspace', function (): void {
    it('is at /app/w/{workspace}/billing, behind the same checks as the other pages of the space', function (): void {
        expect(route('app.workspace.billing', ['workspace' => 'ws'], absolute: false))->toBe('/app/w/ws/billing');

        $middleware = Route::getRoutes()->getByName('app.workspace.billing')?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class);
    });

    it('sends a visitor who is not signed in to the login page', function (): void {
        $team = inventoryTeam();

        $this->get(billingPageUri($team['workspace']))->assertRedirect(route('login'));
    });

    it('is open to every member of the workspace, with the addresses the script calls', function (string $who): void {
        billingOn();
        $team = inventoryTeam();
        $ulid = $team['workspace']->ulid;

        $this->actingAs($team[$who])->get(billingPageUri($team['workspace']))
            ->assertOk()
            ->assertSee('x-data="workspaceBilling"', false)
            ->assertSee('data-url="/api/v1/workspaces/'.$ulid.'/billing"', false)
            ->assertSee('data-checkout-url="/api/v1/workspaces/'.$ulid.'/billing/checkout"', false)
            ->assertSee('data-portal-url="/api/v1/workspaces/'.$ulid.'/billing/portal"', false)
            ->assertSee('data-refresh-url="/api/v1/workspaces/'.$ulid.'/billing/refresh"', false)
            ->assertSee('Upgrade to Pro')
            ->assertSee('Manage the subscription')
            ->assertSee('Only the owner manages the subscription.');
    })->with(['owner', 'editor', 'viewer']);

    it('is open while the instance sells no plan, and says so', function (): void {
        billingOff();
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(billingPageUri($team['workspace']))
            ->assertOk()
            ->assertSee('This service is not offered on this instance.');
    });

    it('is not open to somebody who does not belong to the workspace', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['stranger'])->get(billingPageUri($team['workspace']))->assertNotFound();
    });

    it('is not there for a workspace that does not exist', function (): void {
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get('/app/w/01JZZZZZZZZZZZZZZZZZZZZZZZ/billing')->assertNotFound();
    });

    it('makes the workspace the one the person works in', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Garage');
        app(Workspaces::class)->makeCurrent($team['owner'], $other);

        $this->actingAs($team['owner']->fresh())->get(billingPageUri($team['workspace']))->assertOk();

        expect(app(Workspaces::class)->current($team['owner']->fresh())?->is($team['workspace']))->toBeTrue();
    });

    it('hands the script its words in the language of the page', function (string $language, string $pro, string $confirming): void {
        $this->useLanguage($language);
        billingOn();
        $team = inventoryTeam();

        $page = $this->actingAs($team['owner'])->get(billingPageUri($team['workspace']), ['Accept-Language' => $language])->assertOk();
        $messages = billingPageMessages($page);

        expect($messages)->toHaveKeys([
            'billing.plan.free', 'billing.plan.pro', 'billing.price.month', 'billing.price.year', 'billing.interval.month',
            'billing.status.past_due', 'billing.renews', 'billing.ends', 'billing.usage.of', 'billing.limit.photo_entry',
            'billing.confirming', 'billing.still_pending', 'billing.error.already_subscribed',
        ])
            ->and($messages['billing.plan.pro'])->toBe($pro)
            ->and($messages['billing.confirming'])->toBe($confirming);
    })->with([
        'English' => ['en', 'Pro', 'Payment being confirmed…'],
        'French' => ['fr', 'Pro', 'Paiement en cours de confirmation…'],
    ]);
});

describe('/app/billing', function (): void {
    it('is at /app/billing, behind the same checks as the other pages of the space', function (): void {
        expect(route('app.billing', absolute: false))->toBe('/app/billing');

        $middleware = Route::getRoutes()->getByName('app.billing')?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class);
    });

    it('leads to the subscription page of the workspace the person works in', function (): void {
        $team = inventoryTeam();
        $garage = app(Workspaces::class)->create($team['owner'], 'Garage');
        app(Workspaces::class)->makeCurrent($team['owner'], $garage);

        $this->actingAs($team['owner']->fresh())->get('/app/billing')->assertRedirect(billingPageUri($garage));
    });

    it('leads a member who works in a workspace they do not own to its page all the same', function (): void {
        $team = inventoryTeam();
        app(Workspaces::class)->makeCurrent($team['editor'], $team['workspace']);

        $this->actingAs($team['editor']->fresh())->get('/app/billing')->assertRedirect(billingPageUri($team['workspace']));
    });

    it('sends a visitor who is not signed in through the login page, which brings them back', function (): void {
        $this->get('/app/billing')->assertRedirect(route('login'));

        expect(session('url.intended'))->toEndWith('/app/billing');

        // Signed in, `/app` takes them where they were going: a page of the account.
        $person = inventoryPerson();
        $this->actingAs($person)->get('/app')->assertRedirect(url('/app/billing'));
    });
});

describe('the page of the workspace', function (): void {
    it('links to the subscription when the instance sells plans', function (): void {
        billingOn();
        $team = inventoryTeam();

        $this->actingAs($team['viewer'])->get(route('app.workspace', ['workspace' => $team['workspace']->ulid]))
            ->assertOk()
            ->assertSee('href="'.billingPageUri($team['workspace']).'"', false)
            ->assertSee('Subscription');
    });

    it('has no such link when the instance sells nothing', function (): void {
        billingOff();
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(route('app.workspace', ['workspace' => $team['workspace']->ulid]))
            ->assertOk()
            ->assertDontSee(billingPageUri($team['workspace']), false);
    });
});

describe('the workspaces page and the photo entry', function (): void {
    beforeEach(function (): void {
        config(['services.anthropic.key' => null]);
    });

    it('lists the workspaces whose plan leaves the photo entry out, so that their switch is disabled', function (): void {
        billingOn();
        billingPageOffersPhotoEntry();
        $owner = inventoryPerson();
        $free = app(Workspaces::class)->create($owner, 'Free shop');
        $pro = app(Workspaces::class)->create($owner, 'Pro shop');
        BillingAccount::factory()->for($pro)->subscribed()->create();

        $page = $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();

        expect(billingPageLockedWorkspaces($page))->toContain($free->ulid)->not->toContain($pro->ulid)
            ->and($page->getContent())->toContain('data-billing-url="/app/w/__id__/billing"');
    });

    it('lists only the workspaces the person owns', function (): void {
        billingOn();
        billingPageOffersPhotoEntry();
        $team = inventoryTeam();

        $page = $this->actingAs($team['editor'])->get(route('app.workspaces'))->assertOk();

        expect(billingPageLockedWorkspaces($page))->not->toContain($team['workspace']->ulid);
    });

    it('lists none while the instance sells no plan, or does not offer the photo entry at all', function (bool $plans, bool $photoEntry): void {
        $plans ? billingOn() : billingOff();

        if ($photoEntry) {
            billingPageOffersPhotoEntry();
        }

        $owner = inventoryPerson();
        app(Workspaces::class)->create($owner, 'Shop');

        $page = $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();

        expect(billingPageLockedWorkspaces($page))->toBe([]);
    })->with([
        'no plan, photo entry offered' => [false, true],
        'plans, no photo entry' => [true, false],
        'neither' => [false, false],
    ]);

    it('keeps the choice of an owner whose workspace went back to Free', function (): void {
        billingOn();
        billingPageOffersPhotoEntry();
        $owner = inventoryPerson();
        $shop = app(Workspaces::class)->create($owner, 'Shop');
        $shop->forceFill(['photo_entry_enabled' => true])->save();
        BillingAccount::factory()->for($shop)->subscribed('canceled')->create();

        $page = $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();
        preg_match('/data-photo-entry="([^"]*)"/', $page->getContent(), $match);

        expect(billingPageLockedWorkspaces($page))->toContain($shop->ulid)
            ->and(json_decode(html_entity_decode($match[1], ENT_QUOTES), true)[$shop->ulid])->toBeTrue();
    });
});
