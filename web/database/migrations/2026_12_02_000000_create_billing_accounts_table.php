<?php

declare(strict_types=1);

use App\Database\ExactColumns;
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
 * `payer_id` is the person who started the checkout that created the customer (or that the customer was last replaced for): the owner who
 * pays. When the workspace changes hands the new owner is not the payer, and must not be handed the customer (its email, its invoices) of
 * the old one. The user is not a parent of the row, only a pointer: the column is emptied when the user is deleted.
 *
 * The row hangs from the workspace (cascade), at the first level below it: the chain stays within the three levels MySQL (9.7 at the
 * time of writing) can cascade. Deleting a workspace does not cancel its subscription at Stripe: the application does, in a job. The three
 * identifiers of Stripe compare exactly, case included ({@see ExactColumns}).
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
            // The identifiers of Stripe are case-sensitive: binary collation on MySQL and MariaDB (a lookup and a unique index compare exactly).
            ExactColumns::apply($table->string('stripe_customer_id'))->unique();
            // The owner who pays for the customer; emptied (not cascaded) when that person's account is deleted.
            $table->foreignId('payer_id')->nullable()->index()->constrained('users')->nullOnDelete();
            ExactColumns::apply($table->string('stripe_subscription_id'))->nullable()->index();
            $table->string('status', 32)->nullable();
            ExactColumns::apply($table->string('price_id'))->nullable();
            $table->string('interval', 16)->nullable();
            // All nullable: no `NOT NULL` timestamp for MySQL < 8 and MariaDB < 10.10 (`explicit_defaults_for_timestamp` off) to give an
            // `ON UPDATE CURRENT_TIMESTAMP` to. The application sets them.
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            // With microseconds: `synced_at` is the moment a read of Stripe began, compared with that of another read that may have begun in
            // the same second (see BillingSync).
            $table->timestamp('synced_at', 6)->nullable();
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
