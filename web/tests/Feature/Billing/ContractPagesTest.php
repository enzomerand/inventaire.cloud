<?php

declare(strict_types=1);

use App\Models\BillingAccount;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->and($messages)->toHaveKeys(['billing.error.no_live_subscription', 'billing.error.cancellation_scheduled', 'billing.error.withdrawal_period_over', 'billing.error.already_withdrawn', 'billing.notice.withdrawn', 'billing.withdrawal.until']);
    })->with([
        'English' => ['en', 'Your withdrawal is recorded and your subscription is ending. The acknowledgement is sent to the address you gave.', ':plan subscription, :interval, ordered on :date'],
        'French' => ['fr', "Votre rétractation est enregistrée et votre abonnement prend fin. L'accusé de réception est envoyé à l'adresse que vous avez indiquée.", 'Abonnement :plan, :interval, commandé le :date'],
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

        expect($messages['workspaces.confirm_delete_subscription'])->toBe('Son abonnement Pro prend fin immédiatement, sans remboursement de la période en cours.');
    });
});

describe('the account page', function (): void {
    it('warns, where the instance sells plans, that a workspace deleted with the account ends its subscription at once', function (): void {
        $this->useLanguage('en');
        billingOn();
        $owner = inventoryPerson();

        $this->actingAs($owner)->get(route('app.account', absolute: false))->assertOk()
            ->assertSee('If one of those workspaces has a Pro subscription, it ends at once, without a refund of the period in progress.');

        billingOff();

        $this->actingAs($owner)->get(route('app.account', absolute: false))->assertOk()
            ->assertDontSee('Pro subscription, it ends at once');
    });
});
