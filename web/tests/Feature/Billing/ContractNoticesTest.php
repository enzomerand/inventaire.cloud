<?php

declare(strict_types=1);

use App\Billing\BillingCustomers;
use App\Billing\BillingSync;
use App\Billing\ContractNotices;
use App\Legal\TermsDocument;
use App\Mail\CancellationConfirmedMail;
use App\Mail\SubscriptionConfirmedMail;
use App\Mail\WithdrawalAcknowledgedMail;
use App\Models\BillingAccount;
use App\Models\BillingWithdrawal;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Workspaces;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\Mime\Email;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';
require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The mails the terms of sale promise around a contract, end to end through the mailer of the tests (the queue is synchronous, the mailer
 * keeps what it sends): the confirmation with the terms as a PDF, the receipt of a cancellation, the notice of renewal of a yearly
 * subscription, each sent once, and the PDF itself.
 *
 * The clock is fixed at a known moment, so that the dates written in the mails are exact.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
    config(['app.display_timezone' => 'Europe/Paris', 'billing.currency' => 'eur', 'billing.amounts' => ['month' => 600, 'year' => 6000]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 14:30:00', 'UTC'));
    $GLOBALS['noticePdfBroken'] = false;
});

/**
 * A workspace with an owner who pays, a billing account that has not read its subscription yet, and a Stripe that has the subscription.
 *
 * @return array{stripe: FakeStripe, owner: User, workspace: Workspace, account: BillingAccount, subscription: string}
 */
function noticeTeam(string $interval = 'month', string $status = 'active', string $locale = 'en', ?int $periodEnd = null): array
{
    $stripe = billingOn();
    $owner = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.test', 'locale' => $locale]);
    $workspace = app(Workspaces::class)->create($owner, 'Shop');
    $account = BillingAccount::factory()->for($workspace)->paidBy($owner)->create();
    $stripe->addCustomer($account->stripe_customer_id);
    $subscription = $stripe->subscribe($account->stripe_customer_id, $status, $interval, $periodEnd ?? now()->addDays(30)->getTimestamp());

    return compact('stripe', 'owner', 'workspace', 'account', 'subscription');
}

/**
 * Reads the subscription of an account at Stripe, as the page, the webhook and the schedule do.
 */
function noticeSync(BillingAccount $account): BillingAccount
{
    return app(BillingSync::class)->sync($account->fresh() ?? $account);
}

/**
 * Forgets the mails sent so far (the claim was undone by hand to try a race again).
 */
function noticeMailsFlush(): void
{
    app('mailer')->getSymfonyTransport()->flush();
}

/**
 * The mails sent so far to an address, subject first.
 *
 * @return list<Email>
 */
function noticeMailsTo(string $address): array
{
    return array_values(array_filter(sentMails(), fn (Email $mail): bool => $mail->getTo()[0]->getAddress() === $address));
}

describe('the confirmation of a subscription', function (): void {
    it('is mailed once, with the terms of sale as a PDF, when the subscription becomes active', function (): void {
        $team = noticeTeam();
        $team['stripe']->change($team['subscription'], ['start_date' => now()->getTimestamp()]);

        noticeSync($team['account']);
        noticeSync($team['account']);
        noticeSync($team['account']);

        $mails = noticeMailsTo('ada.owner@example.test');
        $mail = $mails[0];
        $attachments = $mail->getAttachments();
        $text = (string) $mail->getTextBody();

        expect($mails)->toHaveCount(1)
            ->and($mail->getSubject())->toBe('Confirmation of your '.config('app.name').' Pro subscription')
            ->and($attachments)->toHaveCount(1)
            ->and($attachments[0]->getFilename())->toBe('CGV-inventaire-cloud.pdf')
            ->and($attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype())->toBe('application/pdf')
            ->and(substr($attachments[0]->getBody(), 0, 5))->toBe('%PDF-')
            ->and(strlen($attachments[0]->getBody()))->toBeGreaterThan(8000)
            ->and($text)->toContain('Hello Ada Owner,')
            ->toContain('This message is the confirmation of your contract, on a durable medium.')
            ->toContain('Plan: Pro, monthly')
            ->toContain('Workspace: Shop')
            ->toContain('Price: €6 incl. VAT per month')
            ->toContain('Started on: November 10, 2026')
            ->toContain('Current period ends on: December 10, 2026')
            ->toContain('At your express request, the service started immediately')
            ->toContain('You acknowledged that you lose your right of withdrawal once the contract has been fully performed.')
            // The right of withdrawal lasts fourteen days from the start, to the second, in the zone of the instance.
            // The right lasts to the end of the fourteenth day after the one the subscription began (10 November 2026 in Paris), in the zone of the instance.
            ->toContain('until November 24, 2026 11:59 PM (UTC+1)')
            ->toContain('"Withdraw from the contract here"')
            ->toContain('"Cancel your contract"')
            ->toContain('/app/w/'.$team['workspace']->ulid.'/billing')
            ->toContain('Terms of sale (version of October 31, 2026):')
            ->and($mail->getHtmlBody())->toContain('Price: €6 incl. VAT per month')->toContain('/app/w/'.$team['workspace']->ulid.'/billing')
            ->and($team['account']->fresh()->confirmation_sent_for)->toBe($team['subscription']);
    });

    it('is written in the language of the person, and its terms are in French whatever the language', function (): void {
        $team = noticeTeam(locale: 'fr');

        noticeSync($team['account']);

        $mail = noticeMailsTo('ada.owner@example.test')[0];

        expect($mail->getSubject())->toBe('Confirmation de votre abonnement Pro à '.config('app.name'))
            ->and($mail->getTextBody())->toContain('Ce message est la confirmation de votre contrat, sur support durable.')
            ->toContain("Prix : 6\u{00A0}€ TTC par mois")
            ->toContain('« Renoncer au contrat ici »')
            ->toContain('« Résilier votre contrat »')
            ->and($mail->getAttachments()[0]->getFilename())->toBe('CGV-inventaire-cloud.pdf');
    });

    it('is mailed for a trial too, and not for a subscription that is not paid for', function (string $status, int $mails): void {
        $team = noticeTeam(status: $status);

        noticeSync($team['account']);

        expect(noticeMailsTo('ada.owner@example.test'))->toHaveCount($mails);
    })->with([
        'active' => ['active', 1],
        'trialing' => ['trialing', 1],
        'past due' => ['past_due', 0],
        'incomplete' => ['incomplete', 0],
        'unpaid' => ['unpaid', 0],
        'paused' => ['paused', 0],
        'canceled' => ['canceled', 0],
    ]);

    it('is mailed again for a new subscription, once for each', function (): void {
        $team = noticeTeam();
        noticeSync($team['account']);

        $team['stripe']->setStatus($team['subscription'], 'canceled');
        noticeSync($team['account']);
        $second = $team['stripe']->subscribe($team['account']->stripe_customer_id, 'active', 'year', now()->addDays(365)->getTimestamp());
        noticeSync($team['account']);
        noticeSync($team['account']);

        $mails = noticeMailsTo('ada.owner@example.test');

        expect($mails)->toHaveCount(2)
            ->and($mails[1]->getTextBody())->toContain('Plan: Pro, yearly')->toContain('Price: €60 incl. VAT per year')
            ->and($team['account']->fresh()->confirmation_sent_for)->toBe($second);
    });

    it('is sent once when two synchronisations see the same change at the same moment', function (): void {
        $team = noticeTeam();
        $written = noticeSync($team['account']);
        // Both hold the account as it was before either claimed the mail: the column is claimed with one conditional write.
        DB::table('billing_accounts')->where('id', $written->id)->update(['confirmation_sent_for' => null]);
        noticeMailsFlush();
        $one = BillingAccount::query()->findOrFail($written->id);
        $other = BillingAccount::query()->findOrFail($written->id);
        $notices = app(ContractNotices::class);

        $first = $notices->confirmation($one);
        $second = $notices->confirmation($other);

        expect([$first, $second])->toBe([true, false])
            ->and(noticeMailsTo('ada.owner@example.test'))->toHaveCount(1);
    });

    it('is claimed before it is queued and given back when the mail cannot be sent: the next reading sends it', function (): void {
        $team = noticeTeam();
        billingMailBreaksFor();
        Log::spy();

        noticeSync($team['account']);

        expect(sentMails())->toBe([])
            ->and($team['account']->fresh()->confirmation_sent_for)->toBeNull();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'confirmation mail of a subscription could not be queued'))->once();

        config(['mail.default' => 'array']);
        app('mail.manager')->purge();
        noticeSync($team['account']);

        expect(noticeMailsTo('ada.owner@example.test'))->toHaveCount(1)
            ->and($team['account']->fresh()->confirmation_sent_for)->toBe($team['subscription']);
    });

    it('does not break the reading of the subscription when the mail cannot be queued', function (): void {
        $team = noticeTeam();
        billingMailBreaksFor();

        $synced = noticeSync($team['account']);

        expect($synced->status)->toBe('active')->and($synced->stripe_subscription_id)->toBe($team['subscription']);
    });

    it('never goes out without the PDF: the mail fails, is tried again by the queue, and goes out with it', function (): void {
        config(['queue.default' => 'database']);
        $team = noticeTeam();
        View::composer('legal.terms-pdf', function (): void {
            if ($GLOBALS['noticePdfBroken']) {
                throw new RuntimeException('The PDF cannot be made.');
            }
        });
        $GLOBALS['noticePdfBroken'] = true;

        noticeSync($team['account']);

        // Queued, with the claim in the account; the worker builds the mail, and the PDF fails.
        expect(DB::table('jobs')->count())->toBe(1)
            ->and($team['account']->fresh()->confirmation_sent_for)->toBe($team['subscription']);

        billingWorkOnce($this);

        expect(sentMails())->toBe([])
            ->and(DB::table('jobs')->count())->toBe(1)
            ->and((int) DB::table('jobs')->value('attempts'))->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        // The PDF can be made again: the job comes back after its pause, and the mail goes out with the PDF.
        $GLOBALS['noticePdfBroken'] = false;
        $this->travel(2)->minutes();
        billingWorkOnce($this);

        $mail = noticeMailsTo('ada.owner@example.test')[0];

        expect($mail->getAttachments())->toHaveCount(1)
            ->and(substr($mail->getAttachments()[0]->getBody(), 0, 5))->toBe('%PDF-')
            ->and(DB::table('jobs')->count())->toBe(0);
    });

    it('gives up after five tries: the claim is given back, the administrators are told, and the next reading queues it again', function (): void {
        config(['queue.default' => 'database']);
        $team = noticeTeam();
        User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        View::composer('legal.terms-pdf', function (): void {
            if ($GLOBALS['noticePdfBroken']) {
                throw new RuntimeException('The PDF cannot be made.');
            }
        });
        $GLOBALS['noticePdfBroken'] = true;

        noticeSync($team['account']);

        for ($try = 1; $try <= 5; $try++) {
            $this->travel(40)->minutes();
            billingWorkOnce($this);
        }

        expect(sentMails())->toBe([])
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and($team['account']->fresh()->confirmation_sent_for)->toBeNull();

        // The alert to the administrators was queued by the failure; the worker delivers it.
        billingWorkOnce($this);
        $alert = (string) noticeMailsTo('admin@example.test')[0]->getTextBody();

        expect($alert)->toContain('A mail about a contract could not be delivered')->toContain('Stripe customer: '.$team['account']->stripe_customer_id);
    });

    it('is for the payer, else for the owner of the workspace now', function (): void {
        $team = noticeTeam();
        $other = User::factory()->withPersonalWorkspace()->create(['email' => 'second.owner@example.test', 'locale' => 'en']);
        $team['workspace']->members()->attach($other->id, ['role' => 'owner']);

        // The payer is the first owner; once their account is gone the row has no payer, and the owner now gets it.
        $team['account']->forceFill(['payer_id' => null])->save();
        $team['workspace']->members()->detach($team['owner']->id);

        noticeSync($team['account']);

        expect(noticeMailsTo('ada.owner@example.test'))->toBe([])
            ->and(noticeMailsTo('second.owner@example.test'))->toHaveCount(1);
    });

    it('has nobody to write to for a workspace without a payer or an owner, and claims nothing', function (): void {
        $stripe = billingOn();
        $account = billingAccountAt($stripe, 'active');

        noticeSync($account);

        expect(sentMails())->toBe([])->and($account->fresh()->confirmation_sent_for)->toBeNull();
    });

    it('is told to the administrators when it cannot be delivered after every try', function (): void {
        $team = noticeTeam();
        User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        $team['account']->forceFill(['confirmation_sent_for' => $team['subscription']])->save();
        $mail = new SubscriptionConfirmedMail('Ada', 'Shop', 'month', now(), now()->addMonth(), now()->addDays(14), 'https://example.test/billing', 'https://example.test/terms', '2026-10-31', $team['account']->id, $team['subscription']);

        expect($mail->tries)->toBe(5)->and($mail->backoff())->toBe([60, 300, 900, 1800]);

        $mail->failed(new RuntimeException('The mail server is down.'));

        expect($team['account']->fresh()->confirmation_sent_for)->toBeNull()
            ->and((string) noticeMailsTo('admin@example.test')[0]->getTextBody())->toContain('A mail about a contract could not be delivered');
    });
});

describe('the terms of sale as a PDF', function (): void {
    it('lays out the French terms with the form of withdrawal, whatever the language in force, and puts it back', function (): void {
        billingSeller();
        $html = app(TermsDocument::class)->html();

        expect(app()->getLocale())->toBe('en')
            ->and($html)->toContain('<h1>Conditions générales de vente</h1>')
            ->toContain('Version du 31 octobre 2026')
            ->toContain('Article 7 — Droit de rétractation')
            ->toContain('Formulaire type de rétractation')
            ->toContain('(Veuillez compléter et renvoyer le présent formulaire uniquement si vous souhaitez vous rétracter du contrat.)')
            ->toContain("À l'attention de Palan Test, 1 rue de Test, 44000 Nantes, ventes@example.test")
            ->toContain('Commandé le :')
            ->toContain('Signature du (des) consommateur(s)')
            ->toContain('Article L. 215-1.')
            // The numbers and the prices in the text follow the language in force: French here.
            ->toContain("6\u{00A0}€ TTC par mois")
            ->toContain('3 inventaires');
    });

    it('is a PDF made by the library of the application, small enough for a worker', function (): void {
        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        $pdf = app(TermsDocument::class)->pdf();

        $peak = memory_get_peak_usage(true) - $before;

        expect(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(rtrim($pdf))->toEndWith('%%EOF')
            ->and(preg_match_all('/\/Type\s*\/Page(?![a-z])/', $pdf))->toBeGreaterThanOrEqual(3)
            ->and($peak)->toBeLessThan(64 * 1024 * 1024)
            ->and(app()->getLocale())->toBe('en');
    });

    it('is made within a memory limit just above what a worker holds already', function (): void {
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 96 * 1024 * 1024));

        try {
            $pdf = app(TermsDocument::class)->pdf();
        } finally {
            ini_set('memory_limit', (string) $limit);
        }

        expect(substr($pdf, 0, 5))->toBe('%PDF-');
    });
});

describe('the receipt of a cancellation', function (): void {
    it('is mailed once, whoever cancelled and wherever: the date the contract ends and the effects', function (): void {
        $team = noticeTeam();
        $team['account']->forceFill(['confirmation_sent_for' => $team['subscription']])->save();
        noticeSync($team['account']);
        expect(noticeMailsTo('ada.owner@example.test'))->toBe([]);

        // Cancelled from the customer portal, or the dashboard of Stripe: the reading sees the change.
        $team['stripe']->change($team['subscription'], ['cancel_at_period_end' => true]);
        noticeSync($team['account']);
        noticeSync($team['account']);

        $mails = noticeMailsTo('ada.owner@example.test');
        $text = (string) $mails[0]->getTextBody();

        expect($mails)->toHaveCount(1)
            ->and($mails[0]->getSubject())->toBe('Your '.config('app.name').' Pro subscription is cancelled')
            ->and($text)->toContain('We received the cancellation of your Pro subscription to '.config('app.name').'.')
            ->toContain('Plan: Pro, monthly')
            ->toContain('Workspace: Shop')
            ->toContain('Your contract ends on: December 10, 2026')
            ->toContain('Pro stays available until then and you will not be charged again.')
            ->toContain('goes back to the Free plan. Nothing is deleted')
            ->toContain('/app/w/'.$team['workspace']->ulid.'/billing')
            ->and($team['account']->fresh()->cancel_notice_sent_for)->toBe($team['subscription']);
    });

    it('is mailed again for a second cancellation, after the subscription was kept', function (): void {
        $team = noticeTeam();
        $team['account']->forceFill(['confirmation_sent_for' => $team['subscription']])->save();

        foreach ([true, false, true, true] as $cancelling) {
            $team['stripe']->change($team['subscription'], ['cancel_at_period_end' => $cancelling]);
            noticeSync($team['account']);
        }

        expect(noticeMailsTo('ada.owner@example.test'))->toHaveCount(2);
    });

    it('is in French for a person who reads French', function (): void {
        $team = noticeTeam(locale: 'fr');
        $team['account']->forceFill(['confirmation_sent_for' => $team['subscription']])->save();
        $team['stripe']->change($team['subscription'], ['cancel_at_period_end' => true]);

        noticeSync($team['account']);

        $mail = noticeMailsTo('ada.owner@example.test')[0];

        expect($mail->getSubject())->toBe('Votre abonnement Pro à '.config('app.name').' est résilié')
            ->and($mail->getTextBody())->toContain('Votre contrat prend fin le : 10 décembre 2026');
    });

    it('is not mailed for a subscription that ended at once, or that is not live', function (string $status): void {
        $team = noticeTeam(status: $status);
        $team['account']->forceFill(['confirmation_sent_for' => $team['subscription']])->save();
        $team['stripe']->change($team['subscription'], ['cancel_at_period_end' => true]);

        noticeSync($team['account']);

        expect(noticeMailsTo('ada.owner@example.test'))->toBe([]);
    })->with(['canceled', 'incomplete', 'unpaid']);

    it('is claimed once when two readings race, and given back when the mail cannot be sent', function (): void {
        $team = noticeTeam();
        $team['account']->forceFill(['confirmation_sent_for' => $team['subscription']])->save();
        $team['stripe']->change($team['subscription'], ['cancel_at_period_end' => true]);
        billingMailBreaksFor();
        Log::spy();

        noticeSync($team['account']);

        expect($team['account']->fresh()->cancel_notice_sent_for)->toBeNull();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'cancellation mail of a subscription could not be queued'))->once();

        config(['mail.default' => 'array']);
        app('mail.manager')->purge();
        $written = noticeSync($team['account']);
        $stale = BillingAccount::query()->findOrFail($written->id);
        DB::table('billing_accounts')->where('id', $written->id)->update(['cancel_notice_sent_for' => null]);
        noticeMailsFlush();
        $one = BillingAccount::query()->findOrFail($written->id);

        $results = [app(ContractNotices::class)->cancellation($one), app(ContractNotices::class)->cancellation($stale)];

        expect($results)->toBe([true, false])->and(noticeMailsTo('ada.owner@example.test'))->toHaveCount(1);
    });

    it('has a mail that tells when the cancellation was made and cannot be delivered', function (): void {
        $mail = new CancellationConfirmedMail('Ada', 'Shop', 'month', now()->addMonth(), 'https://example.test/billing', 1, 'sub_1');

        expect($mail->tries)->toBe(5)->and($mail->backoff())->toBe([60, 300, 900, 1800]);
    });
});

describe('the notice of renewal of a yearly subscription', function (): void {
    /**
     * A yearly subscription whose period ends in some days, paid by an owner of its own.
     *
     * @param  array<string, mixed>  $attributes  Columns to put over those of the account.
     * @return array{account: BillingAccount, owner: User, workspace: Workspace}
     */
    function noticeYearly(int|float $daysLeft, array $attributes = [], string $email = 'yearly@example.test', string $locale = 'en'): array
    {
        billingOn();
        $owner = User::factory()->withPersonalWorkspace()->create(['name' => 'Yearly Owner', 'email' => $email, 'locale' => $locale]);
        $workspace = app(Workspaces::class)->create($owner, 'Yearly shop');
        $account = BillingAccount::factory()->for($workspace)->paidBy($owner)->subscribed('active', 'year')->create($attributes + [
            'current_period_end' => now()->addSeconds((int) ($daysLeft * 86400)),
        ]);

        return compact('account', 'owner', 'workspace');
    }

    it('is mailed when the period ends in three months or less, once for each period, with the last date in a boxed line', function (): void {
        $yearly = noticeYearly(60);

        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();
        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        $mails = noticeMailsTo('yearly@example.test');
        $text = (string) $mails[0]->getTextBody();
        $html = (string) $mails[0]->getHtmlBody();

        expect($mails)->toHaveCount(1)
            ->and($mails[0]->getSubject())->toBe('Your yearly '.config('app.name').' Pro subscription renews on January 9, 2027')
            ->and($text)->toContain('Hello Yearly Owner,')
            ->toContain('renews automatically for one year')
            // The subscription is billed at that very instant: the box says the time and its zone, and "before".
            ->toMatch('/={20,}\nLast date to refuse the renewal: before January 9, 2027 3:30 PM \(UTC\+1\)\n={20,}/')
            ->toContain('If you do nothing, the renewal is charged €60 incl. VAT per year.')
            ->toContain('free of charge, before that moment')
            ->toContain('/app/w/'.$yearly['workspace']->ulid.'/billing')
            // A box in the HTML as well: a border around the date, and the button that leads to the page.
            ->and($html)->toContain('border:2px solid')->toContain('Last date to refuse the renewal: before January 9, 2027 3:30 PM (UTC+1)')
            ->toContain('>Cancel your contract</a>')
            ->and($yearly['account']->fresh()->renewal_notice_sent_for?->toDateTimeString())->toBe('2027-01-09 14:30:00');
    });

    it('is mailed again for the next period, when the subscription renewed', function (): void {
        $yearly = noticeYearly(60);
        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        // A year later the subscription renewed: another end of period, another notice.
        $this->travel(305)->days();
        $yearly['account']->forceFill(['current_period_end' => now()->addDays(60)])->save();
        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        expect(noticeMailsTo('yearly@example.test'))->toHaveCount(2);
    });

    it('is due from three months to a month before the end, in calendar months of the zone of the instance, both moments included', function (string $now, string $end, int $mails): void {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
        noticeYearly(0, ['current_period_end' => CarbonImmutable::parse($end, 'UTC')]);

        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        expect(noticeMailsTo('yearly@example.test'))->toHaveCount($mails);
    })->with([
        // From 10 November, three months is 92 days and a month is 30: thirty and ninety days would be wrong at both ends.
        'exactly three months before the end' => ['2026-11-10 14:30:00', '2027-02-10 14:30:00', 1],
        'a second short of three months' => ['2026-11-10 14:30:00', '2027-02-10 14:30:01', 0],
        'in between' => ['2026-11-10 14:30:00', '2027-01-09 14:30:00', 1],
        'exactly a month before the end' => ['2026-11-10 14:30:00', '2026-12-10 14:30:00', 1],
        'a second short of a month' => ['2026-11-10 14:30:00', '2026-12-10 14:29:59', 0],
        'a year' => ['2026-11-10 14:30:00', '2027-11-10 14:30:00', 0],
        'already over' => ['2026-11-10 14:30:00', '2026-11-09 14:30:00', 0],
        // From 1 February a month is only 28 days.
        'a month before 1 March is 28 days' => ['2027-02-01 12:00:00', '2027-03-01 12:00:00', 1],
        'a second short of that' => ['2027-02-01 12:00:00', '2027-03-01 11:59:59', 0],
        // Three months before 31 May is 28 February (no overflow into March): 92 days.
        'three months before 31 May are taken back to 28 February' => ['2027-02-28 13:00:00', '2027-05-31 12:00:00', 1],
        'and not the day before' => ['2027-02-27 13:00:00', '2027-05-31 12:00:00', 0],
    ]);

    it('is never mailed for a monthly subscription, nor one that ends by itself, nor one that is not live', function (string $case): void {
        $yearly = noticeYearly(60);
        $account = $yearly['account'];

        match ($case) {
            'monthly' => $account->forceFill(['interval' => 'month'])->save(),
            'ending' => $account->forceFill(['cancel_at_period_end' => true])->save(),
            'canceled' => $account->forceFill(['status' => 'canceled'])->save(),
            'incomplete' => $account->forceFill(['status' => 'incomplete'])->save(),
            'no subscription' => $account->forceFill(['status' => null, 'interval' => null, 'current_period_end' => null])->save(),
        };

        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        expect(sentMails())->toBe([])->and($account->fresh()->renewal_notice_sent_for)->toBeNull();
    })->with(['monthly', 'ending', 'canceled', 'incomplete', 'no subscription']);

    it('is mailed for a yearly subscription that is past due: it is still the contract', function (): void {
        noticeYearly(60, ['status' => 'past_due']);

        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        expect(noticeMailsTo('yearly@example.test'))->toHaveCount(1);
    });

    it('is written in the language of the person', function (): void {
        noticeYearly(60, locale: 'fr');

        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        $mail = noticeMailsTo('yearly@example.test')[0];

        expect($mail->getSubject())->toBe('Votre abonnement annuel Pro à '.config('app.name').' est reconduit le 9 janvier 2027')
            ->and($mail->getTextBody())->toContain('Date limite de non-reconduction : avant le 9 janvier 2027 15:30 (UTC+1)');
    });

    it('does nothing, and reads nothing, while billing is off', function (): void {
        noticeYearly(60);
        billingOff();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->artisan('inventaire:send-renewal-notices')->expectsOutputToContain('Billing is not enabled')->assertSuccessful();

        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        expect(sentMails())->toBe([])->and($sql)->not->toContain('billing_accounts');
    });

    it('is sent once when two runs see the same account at the same moment', function (): void {
        $yearly = noticeYearly(60);
        $one = BillingAccount::query()->findOrFail($yearly['account']->id);
        $other = BillingAccount::query()->findOrFail($yearly['account']->id);
        $notices = app(ContractNotices::class);

        expect([$notices->renewal($one), $notices->renewal($other)])->toBe([true, false])
            ->and(noticeMailsTo('yearly@example.test'))->toHaveCount(1);
    });

    it('goes on with the other accounts when one mail cannot be queued, and tries that one again at the next run', function (): void {
        $broken = noticeYearly(60, email: 'broken@example.test');
        $fine = noticeYearly(61, email: 'fine@example.test');
        billingMailBreaksFor(['broken@example.test']);
        Log::spy();

        $this->artisan('inventaire:send-renewal-notices')->expectsOutputToContain('1 notice(s) queued, 1 failed.')->assertSuccessful();

        expect(noticeMailsTo('fine@example.test'))->toHaveCount(1)
            ->and(noticeMailsTo('broken@example.test'))->toBe([])
            // The claim of the one that failed was given back.
            ->and($broken['account']->fresh()->renewal_notice_sent_for)->toBeNull()
            ->and($fine['account']->fresh()->renewal_notice_sent_for)->not->toBeNull();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'notice of renewal of a subscription could not be queued'))->once();

        config(['mail.default' => 'array']);
        app('mail.manager')->purge();
        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        // The one that was done is not done again; the one that failed is.
        expect(noticeMailsTo('broken@example.test'))->toHaveCount(1)->and(noticeMailsTo('fine@example.test'))->toBe([]);
    });

    it('gives up after three failures in a row: the queue is down, the next account will not do better', function (): void {
        foreach (range(60, 64) as $days) {
            noticeYearly($days, email: "late{$days}@example.test");
        }

        billingMailBreaksFor();
        Log::spy();

        $this->artisan('inventaire:send-renewal-notices')->expectsOutputToContain('Stopped early')->assertSuccessful();

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'notice of renewal of a subscription could not be queued'))->times(3);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'renewal notices stopped early'))->once();
    });

    it('puts the accounts it failed for behind the others, so that they do not starve them', function (): void {
        $first = noticeYearly(60, email: 'first@example.test');
        $second = noticeYearly(61, email: 'second@example.test');
        DB::table('billing_accounts')->where('id', $first['account']->id)->update(['updated_at' => now()->subHour()]);
        DB::table('billing_accounts')->where('id', $second['account']->id)->update(['updated_at' => now()->subMinutes(30)]);
        billingMailBreaksFor(['first@example.test']);

        $this->artisan('inventaire:send-renewal-notices')->assertSuccessful();

        // The one that failed is marked as looked at now; the one that went through is left where it was.
        expect(BillingAccount::query()->find($first['account']->id)->updated_at->equalTo(now()))->toBeTrue()
            ->and(BillingAccount::query()->find($second['account']->id)->updated_at->lessThan(now()))->toBeTrue();
    });

    it('is on the schedule, daily', function (): void {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventaire:send-renewal-notices'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
    });
});

describe('the acknowledgement of a withdrawal that cannot be delivered', function (): void {
    it('empties acknowledged_at and tells the administrators to write to the person', function (): void {
        $team = noticeTeam();
        User::factory()->admin()->create(['email' => 'admin@example.test', 'locale' => 'en']);
        $withdrawal = BillingWithdrawal::factory()->create([
            'workspace_id' => $team['workspace']->id,
            'user_id' => $team['owner']->id,
            'stripe_customer_id' => $team['account']->stripe_customer_id,
            'acknowledged_at' => now(),
        ]);
        $mail = new WithdrawalAcknowledgedMail('Ada', 'ada@example.test', 'Shop', 'month', now()->subDay(), now(), now()->addDays(14), null, null, null, null, null, $withdrawal->id);

        $mail->failed(new RuntimeException('The mail server is down.'));

        expect($withdrawal->fresh()->acknowledged_at)->toBeNull()
            ->and((string) noticeMailsTo('admin@example.test')[0]->getTextBody())->toContain('A mail about a contract could not be delivered')->toContain($team['account']->stripe_customer_id);
    });
});

describe('a confirmation that is late', function (): void {
    /**
     * A confirmation whose right of withdrawal ends at a moment.
     */
    function noticeLateConfirmation(CarbonInterface $withdrawalUntil): SubscriptionConfirmedMail
    {
        return new SubscriptionConfirmedMail('Ada', 'Shop', 'month', now()->subDays(20), now()->addDays(10), $withdrawalUntil, 'https://example.test/billing', 'https://example.test/terms', '2026-10-31', 1, 'sub_1');
    }

    it('does not offer a right of withdrawal that has run out, and still sends the contract, the terms and the way to cancel', function (): void {
        $mail = noticeLateConfirmation(now()->subDays(6));

        $mail->assertDontSeeInText('You can withdraw from the contract')->assertDontSeeInHtml('You can withdraw from the contract')
            ->assertSeeInText('This message is the confirmation of your contract')
            ->assertSeeInText('At your express request, the service started immediately')
            ->assertSeeInText('You can cancel your contract at any time');
    });

    it('offers it while it lasts, up to its last second', function (): void {
        noticeLateConfirmation(now()->addSecond())->assertSeeInText('You can withdraw from the contract, without giving a reason, until');
    });
});

describe('an amount in a mail', function (): void {
    it('has the decimals of its currency: none for the yen, three for the Kuwaiti dinar, two for the euro', function (string $currency, int $paid, int $refund, string $paidLabel, string $refundLabel): void {
        $mail = new WithdrawalAcknowledgedMail('Ada', 'ada@example.test', 'Shop', 'month', now()->subDay(), now(), now()->addDays(14), $paid, $refund, $currency, 1, 30, 1);

        $text = html_entity_decode($mail->render(), ENT_QUOTES);
        $text = str_replace("\u{00A0}", ' ', $text);

        expect($text)->toContain("Amount to be refunded: {$refundLabel}")->toContain("(paid: {$paidLabel};");
    })->with([
        'euro cents' => ['eur', 600, 540, '€6', '€5.40'],
        'yen: no hundredths' => ['jpy', 1500, 1450, '¥1,500', '¥1,450'],
        'Kuwaiti dinar: thousandths' => ['kwd', 6000, 5400, 'KWD 6', 'KWD 5.400'],
    ]);
});

describe('a new customer for a workspace', function (): void {
    it('forgets the start of the old subscription and the notices sent for it', function (): void {
        $stripe = billingOn();
        $owner = User::factory()->withPersonalWorkspace()->create();
        $workspace = app(Workspaces::class)->create($owner, 'Shop');
        $next = User::factory()->withPersonalWorkspace()->create();
        $workspace->members()->attach($next->id, ['role' => 'owner']);
        $account = BillingAccount::factory()->for($workspace)->paidBy($owner)->subscribed('canceled', 'year')->create([
            'started_at' => now()->subYear(),
            'confirmation_sent_for' => 'sub_old',
            'cancel_notice_sent_for' => 'sub_old',
            'renewal_notice_sent_for' => now()->subDays(40),
        ]);
        $stripe->addCustomer($account->stripe_customer_id);

        $fresh = app(BillingCustomers::class)->startFresh($workspace, $account, $next, 'en');

        expect($fresh->stripe_customer_id)->not->toBe($account->stripe_customer_id)
            ->and($fresh->payer_id)->toBe($next->id)
            ->and($fresh->only(['stripe_subscription_id', 'status', 'started_at', 'confirmation_sent_for', 'cancel_notice_sent_for', 'renewal_notice_sent_for']))->toBe([
                'stripe_subscription_id' => null,
                'status' => null,
                'started_at' => null,
                'confirmation_sent_for' => null,
                'cancel_notice_sent_for' => null,
                'renewal_notice_sent_for' => null,
            ])
            ->and($account->fresh()->only(['started_at', 'confirmation_sent_for', 'cancel_notice_sent_for', 'renewal_notice_sent_for']))->each->toBeNull();
    });
});

describe('the schedule', function (): void {
    it('prunes the failed jobs after thirty days, daily: a contract mail that failed holds a name and an address', function (): void {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'queue:prune-failed'));

        expect($events)->toHaveCount(1)
            ->and((string) $events->first()->command)->toContain('--hours=720')
            ->and($events->first()->expression)->toBe('0 0 * * *');
    });
});
