<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds billing accounts for tests and local seeding: a Stripe customer of its own that has no subscription yet and no known payer
 * ({@see self::paidBy()} gives it one). Without a workspace given (`->for($workspace)`), it builds a bare one.
 *
 * @extends Factory<BillingAccount>
 */
class BillingAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'stripe_customer_id' => 'cus_'.Str::random(14),
        ];
    }

    /**
     * Indicate the owner who pays for the customer (who started the checkout it was made for).
     */
    public function paidBy(User $payer): static
    {
        return $this->state(fn (): array => ['payer_id' => $payer->getKey()]);
    }

    /**
     * Indicate a subscription in a status (`active`, `trialing`, `past_due`, `canceled`...), paid for until a month from now.
     */
    public function subscribed(string $status = 'active', string $interval = 'month'): static
    {
        return $this->state(fn (): array => [
            'stripe_subscription_id' => 'sub_'.Str::random(14),
            'status' => $status,
            'price_id' => 'price_'.$interval.'_test',
            'interval' => $interval,
            'current_period_end' => now()->addMonth(),
            'synced_at' => now(),
        ]);
    }
}
