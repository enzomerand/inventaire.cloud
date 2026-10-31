<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Billing;
use App\Billing\ContractNotices;
use App\Billing\WorkspacePlan;
use App\Models\BillingAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the notice of tacit renewal of the yearly subscriptions (article L215-1 of the consumer code, article 6 of the terms of sale): a
 * mail of its own, with the last date to refuse the renewal in a boxed line, between three months and a month before the end of the
 * period.
 *
 * It takes the yearly subscriptions that give Pro and do not end by themselves, whose period ends between three months and one month from
 * now ({@see ContractNotices::renewalDue()}: calendar months in the zone of the instance) and that were not noticed for that period yet,
 * so a notice goes out as soon as one is due and once for each period; monthly subscriptions (indefinite contracts) never get one. The
 * query takes the accounts whose period ends in {@see self::FROM_DAYS} to {@see self::UNTIL_DAYS} days, a little wider than any
 * three months and one month are (a month is 28 to 31 days, three months 89 to 92), and each is judged again exactly. {@see ContractNotices::renewal()} claims
 * the period with one atomic write before it queues the mail, so two runs at the same moment send one.
 *
 * It follows the budget of {@see SyncBillingCommand}: at most {@see self::PER_RUN} accounts in a run, the one looked at longest ago first
 * (an account whose mail could not be queued is marked as looked at, so that one that fails every time does not take the head of every
 * run and starve the others), no new account once {@see self::TIME_BUDGET} seconds are used, and a stop after {@see self::OUTAGE_AFTER}
 * failures in a row (the queue is down, the next account will not do better). One failure does not stop the others; what is left is done
 * by the next run, and a notice that is late is better than none, so the window of a month is not narrowed.
 *
 * Scheduled daily (`routes/console.php`); safe to run at any time and as often as wanted. An instance that sells no plan has nothing to do
 * and says so.
 */
final class SendRenewalNoticesCommand extends Command
{
    /** The accounts looked at in one run. */
    public const PER_RUN = 200;

    /** The failures in a row after which the run gives up. */
    public const OUTAGE_AFTER = 3;

    /** The seconds after which no new account is started. */
    public const TIME_BUDGET = 45;

    /** The days to the end of the period from which the query looks: under a month of any length (28 days at the least), with a margin for the clocks. */
    public const FROM_DAYS = 27;

    /** The days to the end of the period up to which the query looks: over three months of any length (92 days at the most). */
    public const UNTIL_DAYS = 93;

    protected $signature = 'inventaire:send-renewal-notices';

    protected $description = 'Send the notice of tacit renewal of the yearly subscriptions that end in one to three months';

    /**
     * Queues the notices that are due.
     */
    public function handle(ContractNotices $notices): int
    {
        if (! Billing::enabled()) {
            $this->components->info('Billing is not enabled on this instance: no renewal notice to send.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $inARow = 0;
        $started = now();
        $stopped = null;

        foreach ($this->due() as $account) {
            if ($started->diffInSeconds(now(), true) >= self::TIME_BUDGET) {
                $stopped = 'the time budget is used up';

                break;
            }

            try {
                $sent += $notices->renewal($account) ? 1 : 0;
                $inARow = 0;
            } catch (Throwable $exception) {
                $failed++;
                $inARow++;
                $this->markAttempted($account);
                Log::error('The notice of renewal of a subscription could not be queued.', ['billing_account' => $account->getKey(), 'exception' => $exception]);

                if ($inARow >= self::OUTAGE_AFTER) {
                    $stopped = 'the mails cannot be queued';

                    break;
                }
            }
        }

        if ($stopped !== null) {
            Log::warning('The renewal notices stopped early: the next run does the rest.', ['reason' => $stopped, 'sent' => $sent, 'failed' => $failed]);
            $this->components->warn("Stopped early ({$stopped}): the next run does the rest.");
        }

        $this->components->info(sprintf('%d notice(s) queued, %d failed.', $sent, $failed));

        return self::SUCCESS;
    }

    /**
     * Marks an account as looked at, so that it goes behind the others in the next runs: a plain statement on the row, no event.
     */
    private function markAttempted(BillingAccount $account): void
    {
        try {
            BillingAccount::query()->whereKey($account->getKey())->update(['updated_at' => now()]);
        } catch (Throwable $exception) {
            Log::warning('An account whose notice failed could not be marked as looked at.', ['billing_account' => $account->getKey(), 'exception' => $exception]);
        }
    }

    /**
     * The accounts to look at: yearly, giving Pro, not ending by themselves, whose period ends in the wide window and was not noticed for
     * that end; the one written longest ago first, at most {@see self::PER_RUN}. {@see ContractNotices::renewalDue()} judges each again.
     *
     * @return Collection<int, BillingAccount>
     */
    private function due(): Collection
    {
        return BillingAccount::query()
            ->where('interval', 'year')
            ->whereIn('status', WorkspacePlan::PRO_STATUSES)
            ->where('cancel_at_period_end', false)
            ->whereBetween('current_period_end', [now()->addDays(self::FROM_DAYS), now()->addDays(self::UNTIL_DAYS)])
            ->where(fn ($unsent) => $unsent->whereNull('renewal_notice_sent_for')->orWhereColumn('renewal_notice_sent_for', '<>', 'current_period_end'))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::PER_RUN)
            ->get();
    }
}
