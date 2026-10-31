<?php

declare(strict_types=1);

use App\Models\BillingAccount;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * What the pages say of the contract: the subscription page (the two buttons the terms of sale name, the declaration of a withdrawal, the
 * "Coming soon" of an instance that cannot sell yet), the warning before a workspace with a live subscription is deleted, the footer link
 * to the cancellation. Their behaviour is the script's (tests/js); here is the markup the server draws and the words it hands over.
 */

/**
 * The messages the layout hands to the scripts of the page.
 *
 * @return array<string, string>
 */
function contractPageMessages(TestResponse $page): array
{
    preg_match('#<script type="application/json" id="client-messages">(.*?)</script>#s', $page->getContent(), $match);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

describe('the subscription page', function (): void {
    it('has the buttons and the declaration the terms of sale name, in English', function (): void {
        billingOn();
        $this->useLanguage('en');
        $team = inventoryTeam();
        $ulid = $team['workspace']->ulid;

        $this->actingAs($team['owner'])->get(route('app.workspace.billing', $team['workspace'], absolute: false))
            ->assertOk()
            ->assertSee('data-cancel-url="/api/v1/workspaces/'.$ulid.'/billing/cancel"', false)
            ->assertSee('data-withdraw-url="/api/v1/workspaces/'.$ulid.'/billing/withdraw"', false)
            // Prefilled from the account of the person, escaped.
            ->assertSee('data-person-name="Owner"', false)
            ->assertSee('data-person-email="'.$team['owner']->email.'"', false)
            ->assertSee('Cancel your contract')
            ->assertSee('Withdraw from the contract here')
            ->assertSee('Confirm the withdrawal')
            ->assertSee('Coming soon')
            ->assertSee('Email address for the acknowledgement')
            ->assertSee('First and last name');
    });

    it('has the buttons of the terms in French', function (): void {
        billingOn();
        $this->useLanguage('fr');
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(route('app.workspace.billing', $team['workspace'], absolute: false), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Résilier votre contrat')
            ->assertSee('Renoncer au contrat ici')
            ->assertSee('Confirmer la rétractation')
            ->assertSee('Bientôt disponible');
    });

    it('escapes the name of the person in the markup', function (): void {
        billingOn();
        $team = inventoryTeam();
        $team['owner']->forceFill(['name' => '"><script>alert(1)</script>'])->save();

        $this->actingAs($team['owner'])->get(route('app.workspace.billing', $team['workspace'], absolute: false))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    });

    it('hands the script the words of the contract in the language of the page', function (string $language, string $withdrawn, string $contract): void {
        billingOn();
        $this->useLanguage($language);
        $team = inventoryTeam();

        $messages = contractPageMessages($this->actingAs($team['owner'])->get(route('app.workspace.billing', $team['workspace'], absolute: false), ['Accept-Language' => $language])->assertOk());

        expect($messages['billing.withdrawn'])->toBe($withdrawn)
            ->and($messages['billing.withdrawal.contract'])->toBe($contract)
            ->and($messages)->toHaveKeys(['billing.error.no_live_subscription', 'billing.error.cancellation_scheduled', 'billing.error.withdrawal_period_over', 'billing.error.already_withdrawn', 'billing.notice.withdrawn', 'billing.notice.withdrawn_pending', 'billing.withdrawal.until']);
    })->with([
        'English' => ['en', 'Your withdrawal is recorded and your subscription is ending. You receive the acknowledgement at the address you gave.', ':plan subscription, :interval, ordered on :date'],
        'French' => ['fr', "Votre rétractation est enregistrée et votre abonnement prend fin. Vous recevez l'accusé de réception à l'adresse que vous avez indiquée.", 'Abonnement :plan, :interval, commandé le :date'],
    ]);

    it('hands the script no word of billing while the instance sells nothing', function (): void {
        billingOff();
        $team = inventoryTeam();

        $messages = contractPageMessages($this->actingAs($team['owner'])->get(route('app.workspace.billing', $team['workspace'], absolute: false))->assertOk());

        expect(array_filter(array_keys($messages), fn (string $key): bool => str_starts_with($key, 'billing.')))->toBe([])
            ->and($messages)->not->toHaveKey('workspaces.confirm_delete_subscription');
    });
});

describe('the footer', function (): void {
    it('links to the cancellation of the contract where the instance sells, and nowhere else', function (string $state, bool $link): void {
        match ($state) {
            'selling' => billingOn(),
            'incomplete seller' => billingOn(['legal.seller.vat_number' => '']),
            'preview' => billingPreview(),
            'nothing' => billingOff(),
        };
        $this->useLanguage('en');
        $team = inventoryTeam();
        $page = $this->actingAs($team['owner'])->get(route('app.workspaces', absolute: false))->assertOk();

        if ($link) {
            $page->assertSee('href="/app/billing"', false)->assertSee('Cancel your contract');
        } else {
            $page->assertDontSee('href="/app/billing"', false)->assertDontSee('Cancel your contract');
        }
    })->with([
        'selling' => ['selling', true],
        'Stripe on, seller incomplete' => ['incomplete seller', false],
        'preview' => ['preview', false],
        'nothing' => ['nothing', false],
    ]);

    it('is in French', function (): void {
        billingOn();
        $this->useLanguage('fr');
        $team = inventoryTeam();

        $this->actingAs($team['owner'])->get(route('app.workspaces', absolute: false), ['Accept-Language' => 'fr'])->assertOk()->assertSee('Résilier votre contrat');
    });
});

describe('the workspaces page', function (): void {
    /**
     * The ULIDs the page says have a live subscription, decoded as the browser decodes them.
     *
     * @return list<string>
     */
    function contractLiveWorkspaces(TestResponse $page): array
    {
        preg_match('/data-live-subscriptions="([^"]*)"/', $page->getContent(), $match);

        expect($match)->not->toBe([], 'the page has a data-live-subscriptions attribute');

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }

    it('says which workspaces the person owns have a live subscription, so that deleting one warns that it ends at once', function (): void {
        billingOn();
        $owner = inventoryPerson();
        $pro = app(Workspaces::class)->create($owner, 'Pro shop');
        $past = app(Workspaces::class)->create($owner, 'Past due shop');
        $over = app(Workspaces::class)->create($owner, 'Over shop');
        $free = app(Workspaces::class)->create($owner, 'Free shop');
        BillingAccount::factory()->for($pro)->subscribed('active')->create();
        BillingAccount::factory()->for($past)->subscribed('past_due')->create();
        BillingAccount::factory()->for($over)->subscribed('canceled')->create();
        $other = inventoryPerson('Other');
        $theirs = app(Workspaces::class)->create($other, 'Theirs');
        $theirs->members()->attach($owner->id, ['role' => 'editor']);
        BillingAccount::factory()->for($theirs)->subscribed('active')->create();

        $live = contractLiveWorkspaces($this->actingAs($owner)->get(route('app.workspaces', absolute: false))->assertOk());

        // A workspace that is not the person's to delete is not in the list, nor is one whose subscription is over.
        expect($live)->toEqualCanonicalizing([$pro->ulid, $past->ulid]);
    });

    it('says none while the instance sells no plan', function (): void {
        billingOff();
        $owner = inventoryPerson();
        BillingAccount::factory()->for(app(Workspaces::class)->create($owner, 'Shop'))->subscribed('active')->create();

        expect(contractLiveWorkspaces($this->actingAs($owner)->get(route('app.workspaces', absolute: false))->assertOk()))->toBe([]);
    });

    it('hands the script the warning in the language of the page', function (): void {
        billingOn();
        $this->useLanguage('fr');
        $owner = inventoryPerson();

        $messages = contractPageMessages($this->actingAs($owner)->get(route('app.workspaces', absolute: false), ['Accept-Language' => 'fr'])->assertOk());

        expect($messages['workspaces.confirm_delete_subscription'])->toBe("Son abonnement Pro prend fin immédiatement et la période en cours n'est pas remboursée, sous réserve de votre droit de rétractation et de la garantie légale. Si vous êtes dans les 14 jours de rétractation, rétractez-vous d'abord du contrat depuis sa page Abonnement : supprimer l'espace supprime cette page.");
    });
});

describe('the account page', function (): void {
    it('warns, where the instance sells plans, that a workspace deleted with the account ends its subscription at once', function (): void {
        $this->useLanguage('en');
        billingOn();
        $owner = inventoryPerson();

        $this->actingAs($owner)->get(route('app.account', absolute: false))->assertOk()
            ->assertSee('If one of those workspaces has a Pro subscription, it ends at once, and the period in progress is not refunded, subject to your right of withdrawal and the legal guarantee.')
            ->assertSee('If you are within the 14 days of withdrawal, withdraw from the contract first from the Subscription page of that workspace: deleting the workspace removes that page.');

        billingOff();

        $this->actingAs($owner)->get(route('app.account', absolute: false))->assertOk()
            ->assertDontSee('Pro subscription, it ends at once');
    });
});

describe('the terms of sale', function (): void {
    it('stay reachable wherever a contract exists, since the mails of a contract link to them, even when nothing is shown or sold', function (): void {
        // Stripe is set up but the seller is incomplete (nothing is sold) and there is no preview: the plans are not shown.
        billingOn(['legal.seller.vat_number' => '']);
        BillingAccount::factory()->for(Workspace::factory())->subscribed('active')->create();

        $this->get('/terms')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        // Still a working version while the seller is incomplete.
        $this->get('/en/terms')->assertOk()->assertSee('Working version: information to be completed')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        // The pricing page stays a 404 where the plans are not shown.
        $this->get('/pricing')->assertNotFound();
        $this->get('/en/pricing')->assertNotFound();
    });

    it('are complete and indexable once the seller is, wherever a contract exists', function (): void {
        billingOn();
        config(['app.indexable' => true]);
        BillingAccount::factory()->for(Workspace::factory())->subscribed('active')->create();

        $this->get('/en/terms')->assertOk()->assertDontSee('Working version')->assertSee('<meta name="robots" content="index, follow">', false);
    });

    it('are a 404 where nothing is shown and no subscription exists, even when an account does', function (): void {
        // Stripe is set up, the seller is incomplete, nothing is previewed; an account started a checkout and never subscribed.
        billingOn(['legal.seller.vat_number' => '']);
        BillingAccount::factory()->for(Workspace::factory())->create();

        $this->get('/terms')->assertNotFound();
        $this->get('/en/terms')->assertNotFound();
    });

    it('stay reachable for a contract that is over: the account keeps the id of its subscription', function (): void {
        billingOn(['legal.seller.vat_number' => '']);
        BillingAccount::factory()->for(Workspace::factory())->subscribed('canceled')->create();

        $this->get('/terms')->assertOk();
    });

    it('are a 404 while billing is off, whatever the table holds', function (): void {
        billingOff();
        BillingAccount::factory()->for(Workspace::factory())->subscribed('active')->create();

        $this->get('/terms')->assertNotFound();
    });

    it('read nothing while billing is off', function (): void {
        billingOff();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/terms')->assertNotFound();

        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        expect($sql)->not->toContain('billing_accounts');
    });

    it('say how the fourteen days are counted, in French and in the translation', function (): void {
        billingPreview();

        $this->get('/terms')->assertOk()->assertSee("Le jour de la conclusion n'est pas compté ; le délai prend fin à la dernière heure du quatorzième jour et, s'il expire un samedi, un dimanche ou un jour férié ou chômé, il est prorogé jusqu'au premier jour ouvrable suivant.", false);
        $this->get('/en/terms')->assertOk()->assertSee('The day the contract is concluded is not counted; the period ends at the last hour of the fourteenth day and, if it expires on a Saturday, a Sunday or a public or non-working holiday, it is extended to the next working day.');
    });
});
