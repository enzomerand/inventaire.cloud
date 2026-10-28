<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Billing;
use App\Billing\BillingSync;
use App\Billing\StripeException;
use App\Billing\SubscriptionStatus;
use App\Models\BillingAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads again, at Stripe, the subscription of the workspaces that have not been looked at for a while: the safety net of the webhook, and
 * the only way an instance that has no webhook (a local one, or one behind a NAT) ever learns that a subscription changed.
 *
 * It takes the accounts that were never synchronised first, then the ones that are due: an account whose subscription is in play
 * ({@see SubscriptionStatus::CURRENT}: it bills, or may bill again) is read again {@see self::STALE_AFTER_HOURS} hours after it was last
 * read, because a missed webhook costs a wrong plan; an account with no subscription or with one that is over only
 * {@see self::QUIET_AFTER_HOURS} hours after (nothing there changes by itself; the webhook, the page and the checkout read it when something
 * does). The one that was looked at (or created) longest ago comes first, at most {@see self::PER_RUN} per run (the rest waits for the next one). Each
 * is synchronised in the command itself, one after the other, so that a hundred accounts do not make a hundred jobs; one that fails is
 * written in the log and the others go on. An account that fails is marked as looked at (its `updated_at`), so that one that fails every
 * time, for a reason that will not go away, does not take a place at the head of every run and starve the others.
 *
 * Four things stop a run: a configuration that is broken, which makes it fail (the key is refused by Stripe); {@see self::OUTAGE_AFTER}
 * failures in a row of the kind worth trying again later (Stripe cannot be reached, or is failing), since the next account will not do any
 * better; {@see self::LOST_AFTER} answers in a row that Stripe does not know the customer of an account that has a subscription in play
 * (`resource_missing`: the key is far more likely to be for another mode or account than every customer to be gone, and the administrators
 * were told by the first); and the time budget ({@see self::TIME_BUDGET} seconds, not counting the call in progress), which keeps a run from still being
 * there when the next one is due. What is left is done by the next run.
 *
 * Scheduled hourly (`routes/console.php`); safe to run at any time and as often as wanted. An instance that does not sell plans has
 * nothing to do and says so.
 */
final class SyncBillingCommand extends Command
{
    /** The accounts looked at in one run. */
    public const PER_RUN = 200;

    /** The hours after which an account whose subscription is in play is read again. */
    public const STALE_AFTER_HOURS = 6;

    /** The hours after which an account with no subscription, or a subscription that is over, is read again. */
    public const QUIET_AFTER_HOURS = 24;

    /** The failures worth trying again, in a row, after which the run gives up: Stripe is down, not the account. */
    public const OUTAGE_AFTER = 3;

    /** The answers "Stripe does not know this customer", in a row, after which the run gives up: the key is for another mode or account, not every customer deleted. */
    public const LOST_AFTER = 3;

    /** The seconds after which no new account is started (a call in progress may take 20 more). */
    public const TIME_BUDGET = 45;

    protected $signature = 'inventaire:sync-billing';

    protected $description = 'Read again at Stripe the subscriptions of the workspaces that were not synchronised for a few hours';

    /**
     * Synchronises the accounts that are due.
     */
    public function handle(BillingSync $sync): int
    {
        if (! Billing::enabled()) {
            $this->components->info('Billing is not enabled on this instance: nothing to synchronise.');

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        $inARow = 0;
        $lostInARow = 0;
        $started = now();
        $stopped = null;

        foreach ($this->due() as $account) {
            if ($started->diffInSeconds(now(), true) >= self::TIME_BUDGET) {
                $stopped = 'the time budget is used up';

                break;
            }

            try {
                $sync->sync($account);
                $done++;
                $inARow = 0;
                $lostInARow = 0;
            } catch (StripeException $exception) {
                if ($exception->unauthorized() || $exception->code() === 'not_configured') {
                    // The same answer would come for every account: the key is wrong, there is no point in asking again.
                    Log::error('Stripe refused the secret key: check STRIPE_SECRET.', ['status' => $exception->status(), 'reason' => $exception->code()]);
                    $this->components->error('Stripe refused the secret key: check STRIPE_SECRET.');

                    return self::FAILURE;
                }

                $failed++;
                $this->markAttempted($account);
                Log::error('The subscription of a workspace could not be synchronised with Stripe.', [
                    'billing_account' => $account->getKey(),
                    'status' => $exception->status(),
                    'reason' => $exception->code(),
                ]);

                $inARow = $exception->retryable() ? $inARow + 1 : 0;
                $lostInARow = $exception->missing() ? $lostInARow + 1 : 0;

                if ($inARow >= self::OUTAGE_AFTER) {
                    $stopped = 'Stripe fails to answer';

                    break;
                }

                if ($lostInARow >= self::LOST_AFTER) {
                    $stopped = 'Stripe does not know the customers of several accounts in a row (wrong STRIPE_SECRET?)';

                    break;
                }
            } catch (Throwable $exception) {
                $failed++;
                $inARow = 0;
                $lostInARow = 0;
                $this->markAttempted($account);
                Log::error('The subscription of a workspace could not be synchronised.', ['billing_account' => $account->getKey(), 'exception' => $exception]);
            }
        }

        if ($stopped !== null) {
            Log::warning('The synchronisation of the subscriptions stopped early: the next run does the rest.', ['reason' => $stopped, 'synchronised' => $done, 'failed' => $failed]);
            $this->components->warn("Stopped early ({$stopped}): the next run does the rest.");
        }

        $this->components->info(sprintf('%d account(s) synchronised, %d failed.', $done, $failed));

        return self::SUCCESS;
    }

    /**
     * Marks an account as looked at, so that it goes behind the others in the next runs. A plain statement on the row: no event, and none
     * of the locks of a synchronisation.
     */
    private function markAttempted(BillingAccount $account): void
    {
        try {
            BillingAccount::query()->whereKey($account->getKey())->update(['updated_at' => now()]);
        } catch (Throwable $exception) {
            Log::warning('An account that failed to synchronise could not be marked as looked at.', ['billing_account' => $account->getKey(), 'exception' => $exception]);
        }
    }

    /**
     * The accounts to read: those never read first, then the due ones (see the class), each group by the moment the account was last written (created,
     * read, or marked after a failure), the longest ago first, at most {@see self::PER_RUN} in all. Two queries rather than one with an
     * ordering on a column that may be null, which each engine sorts its own way.
     *
     * @return Collection<int, BillingAccount>
     */
    private function due(): Collection
    {
        $never = BillingAccount::query()->whereNull('synced_at')->orderBy('updated_at')->orderBy('id')->limit(self::PER_RUN)->get();
        $room = self::PER_RUN - $never->count();

        if ($room <= 0) {
            return $never;
        }

        $stale = BillingAccount::query()
            ->where(fn (Builder $due) => $due
                ->where(fn (Builder $live) => $live
                    ->whereIn('status', SubscriptionStatus::CURRENT)
                    ->where('synced_at', '<', now()->subHours(self::STALE_AFTER_HOURS)))
                // No status at all is not in `NOT IN (...)`: the engines answer "unknown" for a null.
                ->orWhere(fn (Builder $quiet) => $quiet
                    ->where(fn (Builder $over) => $over->whereNull('status')->orWhereNotIn('status', SubscriptionStatus::CURRENT))
                    ->where('synced_at', '<', now()->subHours(self::QUIET_AFTER_HOURS))))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($room)
            ->get();

        return $never->concat($stale);
    }
}
