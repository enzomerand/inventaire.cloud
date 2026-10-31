<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillingWithdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds withdrawals for tests: a declaration for a subscription of its own, received now.
 *
 * @extends Factory<BillingWithdrawal>
 */
class BillingWithdrawalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stripe_customer_id' => 'cus_'.Str::random(14),
            'stripe_subscription_id' => 'sub_'.Str::random(14),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'submitted_at' => now(),
        ];
    }
}
