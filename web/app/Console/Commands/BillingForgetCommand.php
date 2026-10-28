<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\BillingSync;
use App\Billing\WorkspacePlan;
use App\Database\DeadlockRetries;
use App\Models\BillingAccount;
use App\Models\Workspace;
use App\Workspaces\Locks;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Forgets the Stripe customer of a workspace, and with it the subscription the application remembers: the way out for an administrator
 * who checked in the dashboard of Stripe that the customer really is gone.
 *
 * When Stripe does not know the customer of a workspace that has a subscription in play, the application changes nothing and tells the
 * administrators (`customer_lost`, see {@see BillingSync}): the keys of the instance are far more likely to be wrong than the
 * customer to be deleted, and clearing the row would turn a paying workspace Free. Once somebody has made sure of it (the right keys, the
 * customer and its subscription gone from the right Stripe account), this command deletes the billing account of the workspace: the
 * customer, the payer and the subscription columns go together (the customer is the key of the row, so there is no row without it). The
 * workspace is Free, and its owner's next purchase creates a new customer. Nothing is done at Stripe.
 *
 * It asks for confirmation unless `--force` is given (scripts). It works whether or not billing is on: it only touches the database.
 */
final class BillingForgetCommand extends Command
{
    protected $signature = 'inventaire:billing-forget
        {workspace : ULID of the workspace, as the alert mail gives it}
        {--force : Do not ask for confirmation}';

    protected $description = 'Forget the Stripe customer and the subscription of a workspace, after checking at Stripe that the customer is gone';

    /**
     * Forgets the customer of the workspace after confirmation.
     */
    public function handle(DatabaseManager $db, Locks $locks): int
    {
        $argument = $this->argument('workspace');
        $ulid = strtolower(trim(is_string($argument) ? $argument : ''));

        if (! Str::isUlid($ulid)) {
            $this->components->error('The workspace must be given by its ULID (26 characters).');

            return self::INVALID;
        }

        $workspace = Workspace::query()->where('ulid', $ulid)->first();

        if ($workspace === null) {
            $this->components->error("There is no workspace {$ulid}.");

            return self::FAILURE;
        }

        $account = BillingAccount::query()->where('workspace_id', $workspace->getKey())->first();

        if ($account === null) {
            $this->components->info("The workspace {$ulid} has no Stripe customer: nothing to forget.");

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            'This forgets the Stripe customer %s of the workspace %s (subscription %s, status %s). Nothing is done at Stripe: only do it after checking in the dashboard, with the right keys, that the customer is gone.',
            $account->stripe_customer_id,
            $ulid,
            $account->stripe_subscription_id ?? 'none',
            $account->status ?? 'none',
        ));

        if (! $this->option('force') && ! $this->components->confirm('Forget this customer?', false)) {
            $this->components->info('Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->forget($db, $locks, $workspace, $account)) {
            $this->components->error('The customer of the workspace changed meanwhile: nothing was forgotten. Look at it again and run the command again.');

            return self::FAILURE;
        }

        $this->components->info("The customer {$account->stripe_customer_id} of the workspace {$ulid} was forgotten: the workspace is Free, its next purchase creates a new customer.");

        return self::SUCCESS;
    }

    /**
     * Deletes the account that was shown, under the lock of its workspace; false when the workspace holds another customer by then (a
     * purchase replaced it, or it was forgotten already) and nothing was done.
     */
    private function forget(DatabaseManager $db, Locks $locks, Workspace $workspace, BillingAccount $shown): bool
    {
        try {
            return DeadlockRetries::run(fn (): bool => $db->transaction(function () use ($locks, $workspace, $shown): bool {
                if ($locks->workspaces([$workspace->getKey()])->isEmpty()) {
                    return false;
                }

                $stored = BillingAccount::query()->where('workspace_id', $workspace->getKey())->first();

                if ($stored === null || $stored->stripe_customer_id !== $shown->stripe_customer_id) {
                    return false;
                }

                $stored->delete();

                Log::warning('The Stripe customer of a workspace was forgotten by an administrator.', [
                    'workspace' => $workspace->ulid,
                    'customer' => $stored->stripe_customer_id,
                    'subscription' => $stored->stripe_subscription_id,
                    'status' => $stored->status,
                ]);

                return true;
            }));
        } finally {
            WorkspacePlan::forget();
        }
    }
}
