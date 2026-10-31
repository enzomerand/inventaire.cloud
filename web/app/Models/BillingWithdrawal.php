<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BillingWithdrawalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The proof that somebody withdrew from a subscription (the right of withdrawal, exercised from the subscription page): what they
 * declared, which subscription it was about, when, what had been paid and what is to be refunded.
 *
 * It outlives what it points to: the workspace and the account are emptied when they are deleted (the foreign keys null them), and the row
 * holds the identifiers of Stripe itself. There is one for each subscription (`stripe_subscription_id` is unique).
 *
 * @property int $id
 * @property int|null $workspace_id The workspace, while it exists.
 * @property int|null $user_id The account of the person who declared it, while it exists.
 * @property string $stripe_customer_id
 * @property string $stripe_subscription_id
 * @property string $name The name the person typed.
 * @property string $email The address the acknowledgement was sent to.
 * @property int|null $amount_paid_cents What the latest paid invoice of the subscription was, null when it could not be read.
 * @property int|null $refund_cents What is to be refunded (what was paid, less the share of the service provided), null when the amount is not known.
 * @property string|null $currency Lowercase ISO code of the two amounts.
 * @property CarbonImmutable|null $submitted_at When the declaration was received.
 * @property CarbonImmutable|null $acknowledged_at When the acknowledgement was queued; null while it was not.
 * @property CarbonImmutable|null $admin_told_at When the administrators were mailed what to refund; null while they were not.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace|null $workspace
 * @property-read User|null $user
 */
final class BillingWithdrawal extends Model
{
    /** @use HasFactory<BillingWithdrawalFactory> */
    use HasFactory;

    /**
     * The attributes that can be filled in bulk: everything but the key.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'user_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'name',
        'email',
        'amount_paid_cents',
        'refund_cents',
        'currency',
        'submitted_at',
        'acknowledged_at',
        'admin_told_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'amount_paid_cents' => 'integer',
            'refund_cents' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'admin_told_at' => 'immutable_datetime',
        ];
    }

    /**
     * The workspace the subscription was for, while it exists.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The person who declared the withdrawal, while their account exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
