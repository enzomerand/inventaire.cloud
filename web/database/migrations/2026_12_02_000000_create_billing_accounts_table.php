<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the billing accounts: what the application remembers of the Stripe customer of a workspace and of its subscription.
 *
 * A row exists once a workspace started a checkout (the Stripe customer is created then) and is the only place the application looks
 * at to know the plan of a workspace. The subscription columns are a copy of what Stripe said the last time the application asked
 * (`synced_at`), never what a webhook claimed: they are written by the synchronisation only. `status` is Stripe's own word for the
 * subscription (`active`, `trialing`, `past_due`, `canceled`...), null while the customer has none; `interval` is `month` or `year`;
 * `current_period_end` is read from the subscription item, in UTC.
 *
 * The row hangs from the workspace (cascade), at the first level below it: the chain stays within the three levels MySQL (9.7 at the
 * time of writing) can cascade. Deleting a workspace does not cancel its subscription at Stripe: the application does, in a job.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            // One account per workspace; the unique index is also the one deleting a workspace needs.
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id')->unique();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('status', 32)->nullable();
            $table->string('price_id')->nullable();
            $table->string('interval', 16)->nullable();
            // All nullable: no `NOT NULL` timestamp for MySQL < 8 and MariaDB < 10.10 (`explicit_defaults_for_timestamp` off) to give an
            // `ON UPDATE CURRENT_TIMESTAMP` to. The application sets them.
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_accounts');
    }
};
