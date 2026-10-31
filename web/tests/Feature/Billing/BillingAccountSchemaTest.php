<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
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
            ->toEqualCanonicalizing(['id', 'workspace_id', 'stripe_customer_id', 'payer_id', 'stripe_subscription_id', 'status', 'price_id', 'interval', 'current_period_end', 'cancel_at_period_end', 'started_at', 'confirmation_sent_for', 'cancel_notice_sent_for', 'renewal_notice_sent_for', 'synced_at', 'created_at', 'updated_at'])
            ->and(ApplicationTables::owns('billing_accounts'))->toBeTrue();
    });

    it('is indexed for what reads it: the workspace, the customer a webhook names, and the subscription', function (): void {
        expect(Schema::hasIndex('billing_accounts', ['workspace_id'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('billing_accounts', ['stripe_customer_id'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('billing_accounts', ['stripe_subscription_id']))->toBeTrue();
    });

    it('compares the identifiers of Stripe exactly, case included, in a lookup and in a unique index', function (): void {
        $first = Workspace::factory()->create();
        $second = Workspace::factory()->create();
        rawBillingAccount($first->id, ['stripe_customer_id' => 'cus_AbC', 'stripe_subscription_id' => 'sub_AbC', 'price_id' => 'price_AbC']);
        // Not the same customer: Stripe's ids are case-sensitive, and the unique index agrees on every engine.
        rawBillingAccount($second->id, ['stripe_customer_id' => 'cus_abc', 'stripe_subscription_id' => 'sub_abc', 'price_id' => 'price_abc']);

        expect(BillingAccount::query()->where('stripe_customer_id', 'cus_ABC')->exists())->toBeFalse()
            ->and(BillingAccount::query()->where('stripe_customer_id', 'cus_AbC')->value('workspace_id'))->toBe($first->id)
            ->and(BillingAccount::query()->where('stripe_customer_id', 'cus_abc')->value('workspace_id'))->toBe($second->id)
            ->and(BillingAccount::query()->where('stripe_subscription_id', 'sub_ABC')->exists())->toBeFalse()
            ->and(BillingAccount::query()->where('stripe_subscription_id', 'sub_abc')->value('workspace_id'))->toBe($second->id)
            ->and(BillingAccount::query()->where('price_id', 'PRICE_ABC')->exists())->toBeFalse()
            ->and(BillingAccount::query()->where('price_id', 'price_AbC')->value('workspace_id'))->toBe($first->id);
    });

    it('refuses the same customer twice, in the same case', function (): void {
        rawBillingAccount(Workspace::factory()->create()->id, ['stripe_customer_id' => 'cus_Same']);

        expect(fn () => rawBillingAccount(Workspace::factory()->create()->id, ['stripe_customer_id' => 'cus_Same']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('gives the identifiers of Stripe a binary collation where the engine would otherwise ignore the case', function (): void {
        $collations = collect(Schema::getColumns('billing_accounts'))
            ->whereIn('name', ['stripe_customer_id', 'stripe_subscription_id', 'price_id'])
            ->mapWithKeys(fn (array $column): array => [$column['name'] => $column['collation'] ?? null]);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            expect($collations->all())->toBe(['stripe_customer_id' => 'utf8mb4_bin', 'stripe_subscription_id' => 'utf8mb4_bin', 'price_id' => 'utf8mb4_bin']);
        } else {
            // PostgreSQL compares byte for byte already: the column keeps the default of the database.
            expect($collations->filter(fn (?string $collation): bool => $collation === 'utf8mb4_bin'))->toHaveCount(0);
        }
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
            // With a fraction of a second that is zero, which some engines write out and others leave off.
            ->and(storedDate('billing_accounts', 'synced_at'))->toStartWith('2026-07-01 10:00:00')
            ->and($account->fresh()?->current_period_end)->toBeInstanceOf(DateTimeImmutable::class)
            ->and($account->fresh()?->current_period_end?->toIso8601String())->toBe('2026-07-31T10:00:00+00:00')
            ->and($account->fresh()?->synced_at)->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('keeps the microseconds of synced_at, which the framework\'s date format would drop', function (): void {
        $account = BillingAccount::factory()->create(['synced_at' => CarbonImmutable::parse('2026-07-01 10:00:00.654321', 'UTC')]);

        expect(storedDate('billing_accounts', 'synced_at'))->toStartWith('2026-07-01 10:00:00.654321')
            ->and($account->fresh()?->synced_at?->format('Y-m-d H:i:s.u'))->toBe('2026-07-01 10:00:00.654321')
            ->and($account->fresh()?->synced_at?->getTimezone()->getName())->toBe('UTC');

        $account->forceFill(['synced_at' => null])->save();

        expect($account->fresh()?->synced_at)->toBeNull();
    });

    it('knows who pays: the user of the owner who started the checkout, a pointer that is emptied with the user', function (): void {
        $payer = User::factory()->create();
        $account = BillingAccount::factory()->paidBy($payer)->create();

        expect($account->fresh()?->payer_id)->toBe($payer->id)
            ->and($account->payer->is($payer))->toBeTrue()
            ->and(Schema::hasIndex('billing_accounts', ['payer_id']))->toBeTrue()
            ->and(BillingAccount::factory()->create()->payer_id)->toBeNull();

        // The account of the payer is deleted: the workspace stays with its customer, whose payer is not known any more.
        DB::table('users')->where('id', $payer->id)->delete();

        expect(BillingAccount::query()->whereKey($account->id)->exists())->toBeTrue()
            ->and($account->fresh()?->payer_id)->toBeNull();
    });

    it('refuses a payer that is not a user', function (): void {
        rawBillingAccount(Workspace::factory()->create()->id, ['payer_id' => User::factory()->create()->id + 1000]);
    })->throws(QueryException::class);

    it('holds the longest id Stripe may give', function (): void {
        rawBillingAccount(Workspace::factory()->create()->id, ['stripe_customer_id' => 'cus_'.str_repeat('a', 251), 'stripe_subscription_id' => 'sub_'.str_repeat('b', 251)]);

        expect(BillingAccount::query()->sole()->stripe_customer_id)->toHaveLength(255);
    });
});
