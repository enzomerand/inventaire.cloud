<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Models\BillingAccount;
use App\Models\Workspace;
use App\Settings\InstanceSettings;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 * The ULIDs the workspaces page says are on a plan that includes the photo entry, decoded as the browser decodes them; null when it says
 * that no plan is sold (so that no workspace is locked).
 *
 * @return list<string>|null
 */
function billingPageUnlockedWorkspaces(TestResponse $page): ?array
{
    preg_match('/data-photo-entry-unlocked="([^"]*)"/', $page->getContent(), $match);

    expect($match)->not->toBe([], 'the page has a data-photo-entry-unlocked attribute');

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
            'billing.confirming', 'billing.still_pending', 'billing.unsettled', 'billing.error.already_subscribed', 'billing.error.subscription_pending',
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

    it('hands the period the pricing page links with on to the page, and drops anything else in the query', function (): void {
        $team = inventoryTeam();
        $page = billingPageUri($team['workspace']);
        app(Workspaces::class)->makeCurrent($team['owner'], $team['workspace']);

        $this->actingAs($team['owner']->fresh())->get('/app/billing?interval=year')->assertRedirect($page.'?interval=year');
        $this->actingAs($team['owner']->fresh())->get('/app/billing?interval=month')->assertRedirect($page.'?interval=month');
        $this->actingAs($team['owner']->fresh())->get('/app/billing?interval=decade&x=1')->assertRedirect($page);
        $this->actingAs($team['owner']->fresh())->get('/app/billing?interval[]=year')->assertRedirect($page);
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

    it('lists the workspaces whose plan includes the photo entry, so that the switch of any other is disabled', function (): void {
        billingOn();
        billingPageOffersPhotoEntry();
        $owner = inventoryPerson();
        $free = app(Workspaces::class)->create($owner, 'Free shop');
        $pro = app(Workspaces::class)->create($owner, 'Pro shop');
        BillingAccount::factory()->for($pro)->subscribed()->create();

        $page = $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();

        expect(billingPageUnlockedWorkspaces($page))->toContain($pro->ulid)->not->toContain($free->ulid)
            ->and($page->getContent())->toContain('data-billing-url="/app/w/__id__/billing"');
    });

    it('lists only the workspaces the person owns', function (): void {
        billingOn();
        billingPageOffersPhotoEntry();
        $team = inventoryTeam();
        BillingAccount::factory()->for($team['workspace'])->subscribed()->create();

        $page = $this->actingAs($team['editor'])->get(route('app.workspaces'))->assertOk();

        expect(billingPageUnlockedWorkspaces($page))->toBe([]);
    });

    it('says nothing is locked while the instance sells no plan, or does not offer the photo entry at all', function (bool $plans, bool $photoEntry): void {
        $plans ? billingOn() : billingOff();

        if ($photoEntry) {
            billingPageOffersPhotoEntry();
        }

        $owner = inventoryPerson();
        app(Workspaces::class)->create($owner, 'Shop');

        $page = $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();

        expect(billingPageUnlockedWorkspaces($page))->toBeNull();
    })->with([
        'no plan, photo entry offered' => [false, true],
        'plans, no photo entry' => [true, false],
        'neither' => [false, false],
    ]);

    it('reads the plans of the workspaces in one query, however many are listed', function (): void {
        billingOn();
        billingPageOffersPhotoEntry();
        $owner = inventoryPerson();
        app(Workspaces::class)->create($owner, 'First');
        $reads = function () use ($owner): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();
            DB::disableQueryLog();

            return count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'billing_accounts')));
        };

        $one = $reads();

        foreach (['Second', 'Third', 'Fourth', 'Fifth'] as $name) {
            BillingAccount::factory()->for(app(Workspaces::class)->create($owner, $name))->subscribed()->create();
        }

        expect($one)->toBe(1)->and($reads())->toBe($one);
    });

    it('reads nothing more than the page needs when the instance does not offer the photo entry, whether it sells plans or not', function (bool $plans): void {
        $plans ? billingOn() : billingOff();
        $owner = inventoryPerson();
        $first = app(Workspaces::class)->create($owner, 'First');
        BillingAccount::factory()->for($first)->subscribed()->create();
        $queries = function () use ($owner): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();
            DB::disableQueryLog();

            return array_column(DB::getQueryLog(), 'query');
        };

        $without = $queries();
        billingPageOffersPhotoEntry();
        $with = $queries();
        $memberships = fn (array $sql): int => count(array_filter($sql, fn (string $query): bool => str_contains($query, 'workspace_user')));

        // No plan is read for a switch that is not on the page, and the workspaces of the person are not listed again for it: the only
        // read of them is the one of the layout (the switcher), which the page makes whatever the controller does.
        expect(array_filter($without, fn (string $sql): bool => str_contains($sql, 'billing_accounts')))->toBe([])
            ->and($memberships($without))->toBe(1)
            ->and($memberships($with))->toBe(2);
    })->with([
        'plans sold' => [true],
        'no plan sold' => [false],
    ]);

    it('reads no plan either when plans are not sold, even where the photo entry is offered', function (): void {
        billingOff();
        billingPageOffersPhotoEntry();
        $owner = inventoryPerson();
        BillingAccount::factory()->for(app(Workspaces::class)->create($owner, 'First'))->subscribed()->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();
        DB::disableQueryLog();

        expect(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'billing_accounts')))->toBe([]);
    });

    it('keeps the choice of an owner whose workspace went back to Free', function (): void {
        billingOn();
        billingPageOffersPhotoEntry();
        $owner = inventoryPerson();
        $shop = app(Workspaces::class)->create($owner, 'Shop');
        $shop->forceFill(['photo_entry_enabled' => true])->save();
        BillingAccount::factory()->for($shop)->subscribed('canceled')->create();

        $page = $this->actingAs($owner)->get(route('app.workspaces'))->assertOk();
        preg_match('/data-photo-entry="([^"]*)"/', $page->getContent(), $match);

        expect(billingPageUnlockedWorkspaces($page))->not->toContain($shop->ulid)
            ->and(json_decode(html_entity_decode($match[1], ENT_QUOTES), true)[$shop->ulid])->toBeTrue();
    });
});
