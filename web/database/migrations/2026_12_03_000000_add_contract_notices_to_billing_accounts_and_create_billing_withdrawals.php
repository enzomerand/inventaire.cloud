<?php

declare(strict_types=1);

use App\Database\ExactColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the law asks of a contract sold online, remembered: when the subscription began, which notices were sent for it, and the
 * withdrawals (the right of withdrawal exercised from the subscription page).
 *
 * **`billing_accounts` gets four columns**, all nullable and all written by the application:
 *
 * - `started_at`: the `start_date` of the subscription the row describes, as Stripe reports it. The fourteen days of the right of
 *   withdrawal count from it, and a new subscription starts a new window (the synchronisation writes it with the other subscription
 *   columns);
 * - `confirmation_sent_for`: the id of the subscription whose confirmation (the contract on a durable medium, with the terms and the
 *   withdrawal form) was queued, so that it is sent once for each subscription;
 * - `cancel_notice_sent_for`: the id of the subscription whose cancellation receipt was queued; emptied when the subscription stops
 *   ending, so that a second cancellation is acknowledged too;
 * - `renewal_notice_sent_for`: the end of the period for which the notice of tacit renewal of a yearly subscription was queued, so that
 *   it is sent once for each period.
 *
 * **`billing_withdrawals` is the proof of a withdrawal**: who declared it (the name and the address they typed, the account when it
 * still exists), for which subscription, when, what was paid and what is to be refunded. It must outlive what it points to, so both
 * foreign keys are emptied (not cascaded) when the workspace or the account is deleted, and it holds the Stripe identifiers itself. A
 * subscription is withdrawn from once: the identifier is unique. The row hangs from the workspace at the first level, with no cascade, so
 * the chain MySQL (9.7 at the time of writing) can cascade is not lengthened.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            // Nullable, like the other moments of the table: the application sets them.
            $table->timestamp('started_at')->nullable();
            ExactColumns::apply($table->string('confirmation_sent_for'))->nullable();
            ExactColumns::apply($table->string('cancel_notice_sent_for'))->nullable();
            $table->timestamp('renewal_notice_sent_for')->nullable();
        });

        Schema::create('billing_withdrawals', function (Blueprint $table) {
            $table->id();
            // The proof outlives the workspace and the account: emptied, never cascaded.
            $table->foreignId('workspace_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            ExactColumns::apply($table->string('stripe_customer_id'));
            // One withdrawal for each subscription, whatever two requests do at the same moment.
            ExactColumns::apply($table->string('stripe_subscription_id'))->unique();
            // What the person declared: their name, and the address the acknowledgement was sent to.
            $table->string('name');
            $table->string('email');
            // In the smallest unit of `currency`; null when the invoice could not be read (the administrators were told to check).
            $table->unsignedBigInteger('amount_paid_cents')->nullable();
            $table->unsignedBigInteger('refund_cents')->nullable();
            $table->string('currency', 3)->nullable();
            // Nullable like every moment of the application's tables; the application sets `submitted_at` when it stores the row.
            $table->timestamp('submitted_at')->nullable();
            // When the acknowledgement was queued; null while it was not (the administrators were told).
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_withdrawals');

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'confirmation_sent_for', 'cancel_notice_sent_for', 'renewal_notice_sent_for']);
        });
    }
};
