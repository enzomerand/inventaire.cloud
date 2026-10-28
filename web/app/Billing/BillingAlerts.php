<?php

declare(strict_types=1);

namespace App\Billing;

use App\Mail\BillingAlertMail;
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
 * and again (a synchronisation that runs every few hours) is told once a day for each customer and kind of problem: the day is counted from
 * the moment a mail was queued, and only then, so that a queue that was down does not hide the problem for a day
 * ({@see self::reportProblem()}).
 */
final class BillingAlerts
{
    /** How long a problem that was told is not told again for the same customer. */
    private const REPEAT_AFTER_HOURS = 24;

    /**
     * Queues the mail for every administrator of the instance. The mailable is built for each of them: a mailable keeps the recipients it
     * was sent to.
     *
     * @param  Closure(): BillingAlertMail  $make
     *
     * @throws Throwable When a mail could not be queued: administrators before it were told, those after it were not.
     */
    public function toAdministrators(Closure $make): void
    {
        // A plain read: an administrator who is promoted or demoted at this very moment is not a reason to lock the accounts.
        foreach (User::query()->where('is_admin', true)->orderBy('id')->get(['id', 'email', 'locale']) as $administrator) {
            Mail::to($administrator->email)
                ->locale($administrator->locale ?? config()->string('app.default_locale'))
                ->queue($make());
        }
    }

    /**
     * Tells the administrators of a problem with a customer, at most once a day for that customer and kind of problem. The mark that it
     * was told is written after the mails were queued, so that a failure to queue is told again next time.
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
        $key = 'billing-alert:'.$kind.':'.$customerId;

        if (Cache::has($key)) {
            return;
        }

        $livemode ??= Billing::livemode();
        $this->toAdministrators(fn (): BillingAlertMail => BillingAlertMail::problem($kind, $customerId, $workspaceUlid, $livemode));

        Cache::add($key, true, now()->addHours(self::REPEAT_AFTER_HOURS));
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
}
