<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use App\Models\BillingAccount;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Inserts an account the way a careless script would: straight into the table.
 *
 * @param  array<string, mixed>  $attributes
 */
function rawBillingAccount(int $workspaceId, array $attributes = []): void
{
    DB::table('billing_accounts')->insert($attributes + [
        'workspace_id' => $workspaceId,
        'stripe_customer_id' => 'cus_'.bin2hex(random_bytes(7)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('the table of billing accounts', function (): void {
    it('has the columns the application relies on, and is one of the tables the installer knows', function (): void {
        expect(Schema::getColumnListing('billing_accounts'))
            ->toEqualCanonicalizing(['id', 'workspace_id', 'stripe_customer_id', 'stripe_subscription_id', 'status', 'price_id', 'interval', 'current_period_end', 'cancel_at_period_end', 'synced_at', 'created_at', 'updated_at'])
            ->and(ApplicationTables::owns('billing_accounts'))->toBeTrue();
    });

    it('is indexed for what reads it: the workspace, the customer a webhook names, and the subscription', function (): void {
        expect(Schema::hasIndex('billing_accounts', ['workspace_id'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('billing_accounts', ['stripe_customer_id'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('billing_accounts', ['stripe_subscription_id']))->toBeTrue();
    });

    it('refuses a second account for a workspace', function (): void {
        $workspace = Workspace::factory()->create();
        rawBillingAccount($workspace->id);

        rawBillingAccount($workspace->id);
    })->throws(UniqueConstraintViolationException::class);

    it('refuses a customer shared by two workspaces', function (): void {
        rawBillingAccount(Workspace::factory()->create()->id, ['stripe_customer_id' => 'cus_one']);

        rawBillingAccount(Workspace::factory()->create()->id, ['stripe_customer_id' => 'cus_one']);
    })->throws(UniqueConstraintViolationException::class);

    it('refuses an account for a workspace that is not there', function (): void {
        rawBillingAccount(Workspace::factory()->create()->id + 1000);
    })->throws(QueryException::class);

    it('starts without a subscription, and a subscription that does not end by itself', function (): void {
        rawBillingAccount(Workspace::factory()->create()->id);

        $account = BillingAccount::query()->sole();

        expect($account->stripe_subscription_id)->toBeNull()
            ->and($account->status)->toBeNull()
            ->and($account->price_id)->toBeNull()
            ->and($account->interval)->toBeNull()
            ->and($account->current_period_end)->toBeNull()
            ->and($account->synced_at)->toBeNull()
            ->and($account->cancel_at_period_end)->toBeFalse()
            ->and((new BillingAccount)->cancel_at_period_end)->toBeFalse();
    });

    it('is reached from its workspace, and from the workspace by its account', function (): void {
        $account = BillingAccount::factory()->create();

        expect($account->workspace->is(Workspace::query()->findOrFail($account->workspace_id)))->toBeTrue()
            ->and($account->workspace->billingAccount?->is($account))->toBeTrue()
            ->and(Workspace::factory()->create()->billingAccount)->toBeNull();
    });

    it('is deleted with its workspace, by the database', function (): void {
        $account = BillingAccount::factory()->create();
        $other = BillingAccount::factory()->create();

        DB::table('workspaces')->where('id', $account->workspace_id)->delete();

        expect(BillingAccount::query()->pluck('id')->all())->toBe([$other->id]);
    });

    it('stores its dates in UTC, and gives them back as immutable moments', function (): void {
        $this->travelTo(now()->setDate(2026, 7, 1)->setTime(10, 0, 0, 0));
        $account = BillingAccount::factory()->subscribed()->create(['current_period_end' => now()->addDays(30)]);

        expect(storedDate('billing_accounts', 'current_period_end'))->toBe('2026-07-31 10:00:00')
            ->and(storedDate('billing_accounts', 'synced_at'))->toBe('2026-07-01 10:00:00')
            ->and($account->fresh()?->current_period_end)->toBeInstanceOf(DateTimeImmutable::class)
            ->and($account->fresh()?->current_period_end?->toIso8601String())->toBe('2026-07-31T10:00:00+00:00')
            ->and($account->fresh()?->synced_at)->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('holds the longest id Stripe may give', function (): void {
        rawBillingAccount(Workspace::factory()->create()->id, ['stripe_customer_id' => 'cus_'.str_repeat('a', 251), 'stripe_subscription_id' => 'sub_'.str_repeat('b', 251)]);

        expect(BillingAccount::query()->sole()->stripe_customer_id)->toHaveLength(255);
    });
});
