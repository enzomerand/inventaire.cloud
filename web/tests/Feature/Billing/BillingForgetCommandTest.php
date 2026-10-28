<?php

declare(strict_types=1);

use App\Billing\BillingCustomers;
use App\Billing\Plan;
use App\Billing\WorkspacePlan;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';

/*
 * `inventaire:billing-forget`, the way out for an administrator who checked at Stripe that the customer of a workspace is really gone: it
 * deletes the billing account (the customer, the payer and the subscription columns), after confirmation, and touches nothing at Stripe.
 */

uses(RefreshDatabase::class);

/**
 * A workspace whose customer Stripe lost while the row says it has a subscription in play: the case the command is for.
 *
 * @return array{account: BillingAccount, workspace: Workspace, stripe: FakeStripe}
 */
function billingForgetWorld(): array
{
    $stripe = billingOn();
    $payer = User::factory()->create();
    $account = BillingAccount::factory()->for(Workspace::factory())->paidBy($payer)->subscribed('active')->create();
    $stripe->addCustomer($account->stripe_customer_id)->loseCustomer($account->stripe_customer_id);

    return ['account' => $account, 'workspace' => $account->workspace, 'stripe' => $stripe];
}

describe('forgetting the customer of a workspace', function (): void {
    it('deletes the billing account, leaves the workspace and turns it Free, without a call to Stripe', function (): void {
        Log::spy();
        $world = billingForgetWorld();
        expect(WorkspacePlan::for($world['workspace']))->toBe(Plan::Pro);

        $this->artisan('inventaire:billing-forget', ['workspace' => $world['workspace']->ulid, '--force' => true])
            ->expectsOutputToContain('was forgotten')
            ->assertSuccessful();

        expect(BillingAccount::query()->count())->toBe(0)
            ->and(Workspace::query()->whereKey($world['workspace']->id)->exists())->toBeTrue()
            ->and(WorkspacePlan::for($world['workspace']->fresh()))->toBe(Plan::Free)
            ->and($world['stripe']->calls)->toBe([]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'forgotten by an administrator')
            && $context['workspace'] === $world['workspace']->ulid
            && $context['customer'] === $world['account']->stripe_customer_id
            && $context['subscription'] === $world['account']->stripe_subscription_id
            && $context['status'] === 'active')->once();
    });

    it('lets the workspace buy again: its next checkout makes a customer of its own', function (): void {
        $world = billingForgetWorld();

        $this->artisan('inventaire:billing-forget', ['workspace' => $world['workspace']->ulid, '--force' => true])->assertSuccessful();

        expect(BillingAccount::query()->where('workspace_id', $world['workspace']->id)->exists())->toBeFalse();
        // `find` is what the checkout starts from: with no account it makes the customer.
        expect(app(BillingCustomers::class)->find($world['workspace']))->toBeNull();
    });

    it('asks for confirmation, and changes nothing unless the answer is yes', function (): void {
        $world = billingForgetWorld();

        $this->artisan('inventaire:billing-forget', ['workspace' => $world['workspace']->ulid])
            ->expectsOutputToContain($world['account']->stripe_customer_id)
            ->expectsConfirmation('Forget this customer?', 'no')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        expect(BillingAccount::query()->count())->toBe(1);

        $this->artisan('inventaire:billing-forget', ['workspace' => $world['workspace']->ulid])
            ->expectsConfirmation('Forget this customer?', 'yes')
            ->assertSuccessful();

        expect(BillingAccount::query()->count())->toBe(0);
    });

    it('takes the ULID in capitals, with spaces around it', function (): void {
        $world = billingForgetWorld();

        $this->artisan('inventaire:billing-forget', ['workspace' => '  '.strtoupper($world['workspace']->ulid).' ', '--force' => true])->assertSuccessful();

        expect(BillingAccount::query()->count())->toBe(0);
    });

    it('forgets only the account of the workspace it is given', function (): void {
        $world = billingForgetWorld();
        $other = BillingAccount::factory()->for(Workspace::factory())->subscribed('active')->create();

        $this->artisan('inventaire:billing-forget', ['workspace' => $world['workspace']->ulid, '--force' => true])->assertSuccessful();

        expect(BillingAccount::query()->pluck('id')->all())->toBe([$other->id]);
    });

    it('does nothing for a workspace that has no customer', function (): void {
        $workspace = Workspace::factory()->create();

        $this->artisan('inventaire:billing-forget', ['workspace' => $workspace->ulid, '--force' => true])
            ->expectsOutputToContain('nothing to forget')
            ->assertSuccessful();
    });

    it('refuses a workspace that does not exist, and something that is not a ULID', function (): void {
        $world = billingForgetWorld();

        $this->artisan('inventaire:billing-forget', ['workspace' => strtolower((string) Str::ulid()), '--force' => true])
            ->expectsOutputToContain('There is no workspace')
            ->assertFailed();
        $this->artisan('inventaire:billing-forget', ['workspace' => 'cus_123', '--force' => true])
            ->expectsOutputToContain('must be given by its ULID')
            ->assertExitCode(2);

        expect(BillingAccount::query()->count())->toBe(1);
    });

    it('forgets nothing when the customer of the workspace was replaced after the administrator saw it', function (): void {
        $world = billingForgetWorld();
        $done = false;
        // Once the workspace is locked, another request replaces the customer: the one that was shown is not the one stored any more.
        DB::listen(function ($query) use (&$done, $world): void {
            if (! $done && str_contains(strtolower($query->sql), 'for update') && str_contains($query->sql, 'workspaces')) {
                $done = true;
                DB::table('billing_accounts')->where('id', $world['account']->id)->update(['stripe_customer_id' => 'cus_replaced']);
            }
        });

        $this->artisan('inventaire:billing-forget', ['workspace' => $world['workspace']->ulid, '--force' => true])
            ->expectsOutputToContain('changed meanwhile')
            ->assertFailed();

        expect(BillingAccount::query()->sole()->stripe_customer_id)->toBe('cus_replaced');
    });

    it('works whether or not billing is on: it only touches the database', function (): void {
        $world = billingForgetWorld();
        billingOff();

        $this->artisan('inventaire:billing-forget', ['workspace' => $world['workspace']->ulid, '--force' => true])->assertSuccessful();

        expect(BillingAccount::query()->count())->toBe(0);
    });
});
