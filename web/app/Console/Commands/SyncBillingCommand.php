<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Billing;
use App\Billing\BillingSync;
use App\Billing\StripeException;
use App\Models\BillingAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads again, at Stripe, the subscription of the workspaces that have not been looked at for a while: the safety net of the webhook, and
 * the only way an instance that has no webhook (a local one, or one behind a NAT) ever learns that a subscription changed.
 *
 * It takes the accounts that were never synchronised first, then the ones synchronised more than {@see self::STALE_AFTER_HOURS} hours ago,
 * oldest first, at most {@see self::PER_RUN} per run (the rest waits for the next one). Each is synchronised in the command itself, one after
 * the other, so that a hundred accounts do not make a hundred jobs; one that fails is written in the log and the others go on. Only
 * a configuration that is broken stops the run and makes it fail: the key is refused by Stripe.
 *
 * Scheduled hourly (`routes/console.php`); safe to run at any time and as often as wanted. An instance that does not sell plans has
 * nothing to do and says so.
 */
final class SyncBillingCommand extends Command
{
    /** The accounts looked at in one run. */
    public const PER_RUN = 200;

    /** The hours after which an account is read again. */
    public const STALE_AFTER_HOURS = 6;

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

        foreach ($this->due() as $account) {
            try {
                $sync->sync($account);
                $done++;
            } catch (StripeException $exception) {
                if ($exception->unauthorized() || $exception->code() === 'not_configured') {
                    // The same answer would come for every account: the key is wrong, there is no point in asking again.
                    Log::error('Stripe refused the secret key: check STRIPE_SECRET.', ['status' => $exception->status(), 'reason' => $exception->code()]);
                    $this->components->error('Stripe refused the secret key: check STRIPE_SECRET.');

                    return self::FAILURE;
                }

                $failed++;
                Log::error('The subscription of a workspace could not be synchronised with Stripe.', [
                    'billing_account' => $account->getKey(),
                    'status' => $exception->status(),
                    'reason' => $exception->code(),
                ]);
            } catch (Throwable $exception) {
                $failed++;
                Log::error('The subscription of a workspace could not be synchronised.', ['billing_account' => $account->getKey(), 'exception' => $exception]);
            }
        }

        $this->components->info(sprintf('%d account(s) synchronised, %d failed.', $done, $failed));

        return self::SUCCESS;
    }

    /**
     * The accounts to read: those never read first (oldest first), then the stale ones, the longest ago first, at most {@see self::PER_RUN}
     * in all. Two queries rather than one with an ordering on a column that may be null, which each engine sorts its own way.
     *
     * @return Collection<int, BillingAccount>
     */
    private function due(): Collection
    {
        $never = BillingAccount::query()->whereNull('synced_at')->orderBy('id')->limit(self::PER_RUN)->get();
        $room = self::PER_RUN - $never->count();

        if ($room <= 0) {
            return $never;
        }

        $stale = BillingAccount::query()
            ->where('synced_at', '<', now()->subHours(self::STALE_AFTER_HOURS))
            ->orderBy('synced_at')
            ->orderBy('id')
            ->limit($room)
            ->get();

        return $never->concat($stale);
    }
}
