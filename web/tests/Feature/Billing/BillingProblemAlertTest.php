<?php

declare(strict_types=1);

use App\Billing\BillingAlerts;
use App\Mail\BillingAlertMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../../Support/billing.php';

uses(RefreshDatabase::class);

/*
 * The mails to the administrators about what the application finds wrong with the money of a customer (as opposed to the risks Stripe
 * reports, which StripeWebhookTest holds): what they say in English and in French, and the way they are sent, once a day for a customer.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
    Mail::fake();
});

describe('the mail of a problem', function (): void {
    it('says what happened, what to do, the customer and the workspace, and links to the customer in the dashboard', function (string $kind, string $label, string $advice): void {
        $mail = BillingAlertMail::problem($kind, 'cus_123', '01jworkspace0000000000000x', true);

        $mail->assertSeeInHtml($label)->assertSeeInHtml($advice)->assertSeeInHtml('cus_123')->assertSeeInHtml('01jworkspace0000000000000x')
            ->assertSeeInHtml('<a href="https://dashboard.stripe.com/customers/cus_123"', false);
        $mail->assertSeeInText($label)->assertSeeInText($advice)->assertSeeInText('Stripe customer: cus_123')->assertSeeInText('Workspace: 01jworkspace0000000000000x')
            ->assertSeeInText('https://dashboard.stripe.com/customers/cus_123');
        expect($mail->isProblem())->toBeTrue()
            ->and($mail->envelope()->subject)->toBe('Stripe alert on '.config('app.name').": {$label}")
            // The mail of a problem has nothing of a risk event.
            ->and($mail->render())->not->toContain('Stripe event:')->not->toContain('Amount:')->not->toContain('Stripe reference:');
    })->with([
        'a payment for a workspace that is gone' => ['checkout_orphaned', 'A payment was made for a workspace that no longer exists', 'refund the payment'],
        'a cancellation that failed' => ['cancellation_failed', 'A subscription could not be cancelled', 'Cancel them in the dashboard'],
        'a customer Stripe does not know' => ['customer_lost', 'Stripe does not know the customer of a workspace', 'check STRIPE_SECRET'],
        'a double subscription' => ['double_subscription', 'A customer has two subscriptions', 'cancel the other in the dashboard and refund it'],
    ]);

    it('is written in French, and in the language of each administrator', function (string $kind, string $label, string $advice): void {
        $mail = BillingAlertMail::problem($kind, 'cus_123', '01jworkspace0000000000000x', true);

        $html = html_entity_decode($mail->locale('fr')->render(), ENT_QUOTES);

        expect($html)->toContain($label)->toContain($advice)->toContain('Client Stripe : cus_123')->toContain('Espace de travail : 01jworkspace0000000000000x')
            ->toContain('Quelque chose à propos des paiements demande votre attention');
    })->with([
        ['checkout_orphaned', "Un paiement a été effectué pour un espace de travail qui n'existe plus", 'remboursez le paiement'],
        ['cancellation_failed', "Un abonnement n'a pas pu être résilié", 'Résiliez-les dans le tableau de bord'],
        ['customer_lost', "Stripe ne connaît pas le client d'un espace de travail", 'vérifiez STRIPE_SECRET'],
        ['double_subscription', 'Un client a deux abonnements', 'résiliez l\'autre dans le tableau de bord et remboursez-le'],
    ]);

    it('leaves out the workspace when it is not known, and says it comes from the test mode of Stripe', function (): void {
        $mail = BillingAlertMail::problem('cancellation_failed', 'cus_123', null, false);

        $mail->assertDontSeeInHtml('Workspace:')->assertDontSeeInText('Workspace:')
            ->assertSeeInHtml('This alert comes from the test mode of Stripe.')->assertSeeInText('This alert comes from the test mode of Stripe.')
            ->assertSeeInHtml('<a href="https://dashboard.stripe.com/test/customers/cus_123"', false);
        expect($mail->workspaceUlid)->toBeNull();
    });

    it('is not an event of Stripe that the webhook would follow', function (): void {
        foreach (array_keys(BillingAlertMail::PROBLEMS) as $kind) {
            expect(BillingAlertMail::handles($kind))->toBeFalse();
        }

        expect(BillingAlertMail::handles('charge.dispute.created'))->toBeTrue();
    });

    it('keeps a risk event as it was: the same lines, no explanation', function (): void {
        $mail = new BillingAlertMail('charge.dispute.created', 'du_1', 600, 'eur', true);

        $mail->assertSeeInText('Stripe event: charge.dispute.created')->assertSeeInText('Stripe reference: du_1')->assertSeeInText('Amount: 6.00 EUR')
            ->assertSeeInText('Stripe reported something that needs your attention')
            ->assertDontSeeInText('Stripe customer:');
        expect($mail->isProblem())->toBeFalse()->and($mail->workspaceUlid)->toBeNull();
    });
});

describe('telling the administrators', function (): void {
    it('queues one mail for each administrator, in their language, and none for the others', function (): void {
        User::factory()->admin()->create(['email' => 'english@example.test', 'locale' => 'en']);
        User::factory()->admin()->create(['email' => 'french@example.test', 'locale' => 'fr']);
        User::factory()->admin()->create(['email' => 'default@example.test', 'locale' => null]);
        User::factory()->create(['email' => 'member@example.test']);

        app(BillingAlerts::class)->tellProblem('double_subscription', 'cus_1', '01jws', false);

        Mail::assertQueued(BillingAlertMail::class, 3);
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('english@example.test') && $mail->locale === 'en' && ! $mail->hasTo('french@example.test'));
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('french@example.test') && $mail->locale === 'fr');
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('default@example.test') && $mail->locale === config('app.default_locale'));
        Mail::assertNotQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->hasTo('member@example.test'));
    });

    it('tells a problem once a day for a customer and kind, and again the day after', function (): void {
        User::factory()->admin()->create();
        $alerts = app(BillingAlerts::class);

        $alerts->tellProblem('cancellation_failed', 'cus_1', null);
        $alerts->tellProblem('cancellation_failed', 'cus_1', '01jws');
        // Another kind, or another customer, is another problem.
        $alerts->tellProblem('double_subscription', 'cus_1', null);
        $alerts->tellProblem('cancellation_failed', 'cus_2', null);

        Mail::assertQueued(BillingAlertMail::class, 3);

        $this->travel(23)->hours();
        $alerts->tellProblem('cancellation_failed', 'cus_1', null);
        Mail::assertQueued(BillingAlertMail::class, 3);

        $this->travel(2)->hours();
        $alerts->tellProblem('cancellation_failed', 'cus_1', null);
        Mail::assertQueued(BillingAlertMail::class, 4);
    });

    it('marks a problem as told only once its mails are queued', function (): void {
        User::factory()->admin()->create();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('The queue is down.'));

        expect(fn () => app(BillingAlerts::class)->tellProblem('customer_lost', 'cus_1', null))->toThrow(RuntimeException::class);
        expect(Cache::has('billing-alert:customer_lost:cus_1'))->toBeFalse();
    });

    it('takes the mode of Stripe from the key of the instance unless told', function (): void {
        User::factory()->admin()->create();
        billingOn();

        app(BillingAlerts::class)->tellProblem('customer_lost', 'cus_1', null);
        app(BillingAlerts::class)->tellProblem('customer_lost', 'cus_2', null, true);

        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->objectId === 'cus_1' && ! $mail->livemode);
        Mail::assertQueued(BillingAlertMail::class, fn (BillingAlertMail $mail): bool => $mail->objectId === 'cus_2' && $mail->livemode);
    });

    it('reports a problem without throwing: a mail that cannot be queued is written in the log', function (): void {
        Log::spy();
        User::factory()->admin()->create();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('The queue is down.'));

        app(BillingAlerts::class)->reportProblem('customer_lost', 'cus_1', '01jws');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'administrators could not be told') && $context['kind'] === 'customer_lost' && $context['customer'] === 'cus_1')->once();
    });

    it('has nobody to tell on an instance without administrator, and says nothing of it', function (): void {
        app(BillingAlerts::class)->reportProblem('customer_lost', 'cus_1', null);

        Mail::assertNothingQueued();
    });
});
