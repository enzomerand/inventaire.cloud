<?php

declare(strict_types=1);

namespace App\Billing;

use App\Mail\BillingAlertMail;
use App\Models\BillingWithdrawal;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the administrators of the instance by mail what nothing else would: a risk Stripe reported, or something the application found
 * wrong with the money of a customer (a payment for a workspace that is gone, a subscription it could not cancel, a customer Stripe does
 * not know, a double subscription). Each is something to look at in the dashboard of Stripe, and nobody reads the log of an instance
 * every day.
 *
 * One mail for each administrator, in their language, queued like any mail that holds no secret. A problem the application finds again
 * and again (a synchronisation that runs every few hours) is told once a day for each customer and kind of problem (once a day for the
 * whole instance for the kinds of {@see self::INSTANCE_WIDE}): the day is claimed
 * with one atomic write of the cache before the mails are queued (two requests that find the same problem at the same moment tell it
 * once), and given back when nobody could be told, so that a queue that was down, or an instance with no administrator yet, does not hide
 * the problem for a day ({@see self::tellProblem()}).
 */
final class BillingAlerts
{
    /** How long a problem that was told is not told again for the same customer. */
    private const REPEAT_AFTER_HOURS = 24;

    /**
     * The kinds of problem that are told once a day for the whole instance, whichever customer they are about: a customer Stripe does not
     * know is most often the sign of keys switched to another mode or account, and then every customer is lost at once, which must not
     * be one mail for each of them. The mail names the first one and says that others may follow; the log lists each.
     */
    private const INSTANCE_WIDE = ['customer_lost'];

    /**
     * Queues the mail for every administrator of the instance. The mailable is built for each of them: a mailable keeps the recipients it
     * was sent to.
     *
     * @param  Closure(): BillingAlertMail  $make
     * @param  int  $told  Set to how many administrators were told as the mails are queued, so that a caller that gets the exception
     *                     below still knows how many were: those before the one that failed.
     * @return int How many administrators were told; 0 when the instance has none.
     *
     * @throws Throwable When a mail could not be queued: administrators before it were told, those after it were not.
     */
    public function toAdministrators(Closure $make, int &$told = 0): int
    {
        $told = 0;

        // A plain read: an administrator who is promoted or demoted at this very moment is not a reason to lock the accounts.
        foreach (User::query()->where('is_admin', true)->orderBy('id')->get(['id', 'email', 'locale']) as $administrator) {
            Mail::to($administrator->email)
                ->locale($administrator->locale ?? config()->string('app.default_locale'))
                ->queue($make());
            $told++;
        }

        return $told;
    }

    /**
     * Tells the administrators of a problem with a customer, at most once a day for that customer and kind of problem (for the whole
     * instance, whichever the customer, for the kinds of {@see self::INSTANCE_WIDE}). The day is claimed
     * first, with one atomic write of the cache, so that two requests finding the problem together tell it once; the claim is given back
     * when the mails could not be queued, and when there was nobody to tell (an instance with no administrator: the problem is written in
     * the log as an error, and told as soon as there is one).
     *
     * @param  string  $kind  One of {@see BillingAlertMail::PROBLEMS}.
     * @param  string  $customerId  The customer at Stripe (`cus_…`).
     * @param  string|null  $workspaceUlid  The workspace the customer belonged to, when it is known.
     * @param  bool|null  $livemode  Whether it happened in the live mode of Stripe; as the secret key of the instance says when null.
     *
     * @throws Throwable When a mail could not be queued.
     */
    public function tellProblem(string $kind, string $customerId, ?string $workspaceUlid, ?bool $livemode = null): void
    {
        $key = 'billing-alert:'.$kind.(in_array($kind, self::INSTANCE_WIDE, true) ? '' : ':'.$customerId);

        // Atomic: of two requests that find the problem at the same moment, only one gets to tell it.
        if (! Cache::add($key, true, now()->addHours(self::REPEAT_AFTER_HOURS))) {
            return;
        }

        $livemode ??= Billing::livemode();

        try {
            $told = $this->toAdministrators(fn (): BillingAlertMail => BillingAlertMail::problem($kind, $customerId, $workspaceUlid, $livemode));
        } catch (Throwable $exception) {
            // Nobody may have been told: the next time the problem is found, it is told again.
            Cache::forget($key);

            throw $exception;
        }

        if ($told === 0) {
            Cache::forget($key);
            Log::error('A problem with a Stripe customer could not be told to anybody: the instance has no administrator (inventaire:make-admin creates one).', [
                'kind' => $kind,
                'customer' => $customerId,
                'workspace' => $workspaceUlid,
            ]);
        }
    }

    /**
     * Like {@see self::tellProblem()}, for a caller that has other things to do whatever happens (a synchronisation, a job that gives up):
     * a mail that could not be queued is written in the log and nothing is thrown.
     */
    public function reportProblem(string $kind, string $customerId, ?string $workspaceUlid, ?bool $livemode = null): void
    {
        try {
            $this->tellProblem($kind, $customerId, $workspaceUlid, $livemode);
        } catch (Throwable $exception) {
            Log::error('The administrators could not be told of a problem with a Stripe customer.', ['kind' => $kind, 'customer' => $customerId, 'exception' => $exception]);
        }
    }

    /**
     * Tells the administrators that a customer withdrew from their contract and what to refund: the amount paid, the refund (the part not
     * used) or that the amount is to be checked, the deadline, and whether the acknowledgement reached the customer. Not once a day: each
     * withdrawal is told once ({@see BillingWithdrawal::$admin_told_at} remembers it, and the schedule tells the ones that were not). Never
     * throws: the withdrawal is stored, and a mail that cannot be queued is written in the log with everything the mail would have said,
     * so that the refund is not lost.
     *
     * @param  bool  $acknowledged  Whether the acknowledgement to the customer was queued.
     * @return int How many administrators were told: at least one is what makes the withdrawal told. A failure after some were told does
     *             not count them as nobody.
     */
    public function tellWithdrawal(BillingWithdrawal $withdrawal, ?string $workspaceUlid, bool $acknowledged): int
    {
        $submittedAt = $withdrawal->submitted_at ?? now();
        $told = 0;

        try {
            $this->toAdministrators(fn (): BillingAlertMail => BillingAlertMail::withdrawal(
                $withdrawal->stripe_customer_id,
                $withdrawal->stripe_subscription_id,
                $workspaceUlid,
                $withdrawal->amount_paid_cents,
                $withdrawal->refund_cents,
                $withdrawal->currency,
                $submittedAt,
                $acknowledged,
                Billing::livemode(),
            ), $told);
        } catch (Throwable $exception) {
            Log::error('The administrators could not all be told of a withdrawal.', ['withdrawal' => $withdrawal->getKey(), 'told' => $told, 'exception' => $exception]);
        }

        if ($told === 0) {
            Log::error('A customer withdrew from their contract and nobody was told to refund them: refund them in the dashboard of Stripe.', [
                'withdrawal' => $withdrawal->getKey(),
                'customer' => $withdrawal->stripe_customer_id,
                'subscription' => $withdrawal->stripe_subscription_id,
                'amount_paid_cents' => $withdrawal->amount_paid_cents,
                'refund_cents' => $withdrawal->refund_cents,
                'currency' => $withdrawal->currency,
            ]);
        }

        return $told;
    }
}
