<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use App\Models\BillingAccount;
use App\Models\BillingWithdrawal;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * The withdrawals (the proof that somebody withdrew from a contract) and the columns of the account that remember which notices were sent:
 * what the tables hold, what is unique, and what outlives the workspace and the account.
 */

describe('the table of withdrawals', function (): void {
    it('has the columns the application relies on, and is one of the tables the installer knows', function (): void {
        expect(Schema::getColumnListing('billing_withdrawals'))
            ->toEqualCanonicalizing(['id', 'workspace_id', 'user_id', 'stripe_customer_id', 'stripe_subscription_id', 'name', 'email', 'amount_paid_cents', 'refund_cents', 'currency', 'submitted_at', 'acknowledged_at', 'admin_told_at', 'created_at', 'updated_at'])
            ->and(ApplicationTables::owns('billing_withdrawals'))->toBeTrue();
    });

    it('holds one withdrawal for each subscription, whatever two requests do at the same moment', function (): void {
        BillingWithdrawal::factory()->create(['stripe_subscription_id' => 'sub_1']);

        expect(Schema::hasIndex('billing_withdrawals', ['stripe_subscription_id'], 'unique'))->toBeTrue()
            ->and(fn () => BillingWithdrawal::factory()->create(['stripe_subscription_id' => 'sub_1']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('compares the identifiers of Stripe exactly, case included', function (): void {
        BillingWithdrawal::factory()->create(['stripe_subscription_id' => 'sub_AbC', 'stripe_customer_id' => 'cus_AbC']);
        BillingWithdrawal::factory()->create(['stripe_subscription_id' => 'sub_abc', 'stripe_customer_id' => 'cus_abc']);

        expect(BillingWithdrawal::query()->where('stripe_subscription_id', 'sub_ABC')->exists())->toBeFalse()
            ->and(BillingWithdrawal::query()->where('stripe_subscription_id', 'sub_abc')->value('stripe_customer_id'))->toBe('cus_abc')
            ->and(BillingWithdrawal::query()->where('stripe_customer_id', 'cus_ABC')->exists())->toBeFalse();
    });

    it('outlives the workspace and the account: their keys are emptied, never cascaded, with a raw query too', function (): void {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Shop']);
        $withdrawal = BillingWithdrawal::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'name' => 'Ada', 'amount_paid_cents' => 600, 'currency' => 'eur']);

        DB::table('workspaces')->delete();

        expect($withdrawal->fresh()->workspace_id)->toBeNull()->and($withdrawal->fresh()->user_id)->toBe($user->id);

        DB::table('users')->delete();

        expect(BillingWithdrawal::query()->count())->toBe(1)
            ->and($withdrawal->fresh()->user_id)->toBeNull()
            ->and($withdrawal->fresh()->only(['name', 'amount_paid_cents', 'currency']))->toBe(['name' => 'Ada', 'amount_paid_cents' => 600, 'currency' => 'eur']);
    });

    it('keeps its row when the workspace is deleted through the model, with its billing account going the usual way', function (): void {
        $workspace = Workspace::factory()->create();
        BillingAccount::factory()->for($workspace)->subscribed()->create();
        BillingWithdrawal::factory()->create(['workspace_id' => $workspace->id]);

        $workspace->delete();

        expect(BillingAccount::query()->count())->toBe(0)->and(BillingWithdrawal::query()->count())->toBe(1);
    });
});

describe('the columns of the notices on the billing account', function (): void {
    it('compare the subscription they remember exactly, case included, and start empty', function (): void {
        $workspace = Workspace::factory()->create();
        $account = BillingAccount::factory()->for($workspace)->create();

        expect($account->fresh()->only(['started_at', 'confirmation_sent_for', 'cancel_notice_sent_for', 'renewal_notice_sent_for']))->toBe([
            'started_at' => null, 'confirmation_sent_for' => null, 'cancel_notice_sent_for' => null, 'renewal_notice_sent_for' => null,
        ]);

        $account->forceFill(['confirmation_sent_for' => 'sub_AbC'])->save();

        expect(BillingAccount::query()->where('confirmation_sent_for', 'sub_abc')->exists())->toBeFalse()
            ->and(BillingAccount::query()->where('confirmation_sent_for', 'sub_AbC')->exists())->toBeTrue();
    });
});

describe('the migration', function (): void {
    it('takes an account that already has a subscription for confirmed, and for acknowledged when the subscription is set to end, so that the first reading sends no late mail', function (): void {
        $migration = require database_path('migrations/2026_12_03_000000_add_contract_notices_to_billing_accounts_and_create_billing_withdrawals.php');
        $migration->down();

        try {
            $running = Workspace::factory()->create();
            $ending = Workspace::factory()->create();
            $none = Workspace::factory()->create();
            DB::table('billing_accounts')->insert([
                ['workspace_id' => $running->id, 'stripe_customer_id' => 'cus_run', 'stripe_subscription_id' => 'sub_Run', 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now()],
                ['workspace_id' => $ending->id, 'stripe_customer_id' => 'cus_end', 'stripe_subscription_id' => 'sub_End', 'cancel_at_period_end' => true, 'created_at' => now(), 'updated_at' => now()],
                ['workspace_id' => $none->id, 'stripe_customer_id' => 'cus_none', 'stripe_subscription_id' => null, 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migration->up();

            $rows = DB::table('billing_accounts')->orderBy('id')->get(['stripe_customer_id', 'confirmation_sent_for', 'cancel_notice_sent_for', 'started_at', 'renewal_notice_sent_for'])->keyBy('stripe_customer_id');

            expect($rows['cus_run']->confirmation_sent_for)->toBe('sub_Run')
                ->and($rows['cus_run']->cancel_notice_sent_for)->toBeNull()
                ->and($rows['cus_end']->confirmation_sent_for)->toBe('sub_End')
                ->and($rows['cus_end']->cancel_notice_sent_for)->toBe('sub_End')
                ->and($rows['cus_none']->confirmation_sent_for)->toBeNull()
                ->and($rows['cus_none']->cancel_notice_sent_for)->toBeNull()
                ->and($rows['cus_run']->started_at)->toBeNull()
                ->and($rows['cus_run']->renewal_notice_sent_for)->toBeNull();
        } finally {
            // MySQL and MariaDB commit at each schema change: the rows of this test are deleted by hand.
            DB::table('billing_accounts')->delete();
            DB::table('workspaces')->delete();
        }
    });
});
