<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\WorkspacePlan;
use Carbon\CarbonImmutable;
use Database\Factories\BillingAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the application remembers of the Stripe customer of a workspace and of its subscription.
 *
 * The subscription columns are a copy of what Stripe answered the last time the application asked (`synced_at`); they are written
 * by the synchronisation only, never from the content of a webhook. {@see WorkspacePlan} turns `status` into the plan of the workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $stripe_customer_id
 * @property string|null $stripe_subscription_id The subscription the columns below describe; null while the customer has none.
 * @property string|null $status Stripe's word for it (`active`, `trialing`, `past_due`, `canceled`...).
 * @property string|null $price_id
 * @property string|null $interval `month` or `year`.
 * @property CarbonImmutable|null $current_period_end The end of the period that is paid for, in UTC.
 * @property bool $cancel_at_period_end Whether the subscription ends by itself at the end of the period.
 * @property CarbonImmutable|null $synced_at When the columns above were last read from Stripe.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
final class BillingAccount extends Model
{
    /** @use HasFactory<BillingAccountFactory> */
    use HasFactory;

    /**
     * Has {@see WorkspacePlan} read the plans again once a subscription is written or removed, whichever way the model is saved (the
     * synchronisation, a factory, a console command): what it remembered of the workspaces for the request or job is out of date.
     */
    protected static function booted(): void
    {
        $forget = static function (): void {
            WorkspacePlan::forget();
        };

        self::saved($forget);
        self::deleted($forget);
    }

    /**
     * The attributes that can be filled in bulk: everything but the key.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'status',
        'price_id',
        'interval',
        'current_period_end',
        'cancel_at_period_end',
        'synced_at',
    ];

    /**
     * The attributes a new account starts with: a subscription that does not end by itself, as the column does.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['cancel_at_period_end' => false];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * The workspace that pays.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
