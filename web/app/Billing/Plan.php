<?php

declare(strict_types=1);

namespace App\Billing;

use InvalidArgumentException;

/**
 * A plan a workspace can be on, and what it allows. The values are read from `config/billing.php` each time (`plans.<plan>`), so that
 * an administrator sets them by environment variable and a test by configuration.
 *
 * The plan of a workspace is worked out by {@see WorkspacePlan}; the limits that apply to it, by {@see WorkspaceLimits}.
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';

    /** The numeric limits of a plan. */
    public const LIMITS = ['inventories', 'items', 'storage_bytes', 'members', 'share_links'];

    /** The features a plan allows or not. */
    public const FEATURES = ['photo_entry'];

    /**
     * A numeric limit of the plan: `inventories` (per workspace), `items` (per inventory), `storage_bytes` (per workspace), `members` (per
     * workspace, waiting invitations included) or `share_links` (per inventory). Never negative.
     *
     * @throws InvalidArgumentException When the key is not one of {@see self::LIMITS}.
     */
    public function limit(string $key): int
    {
        if (! in_array($key, self::LIMITS, true)) {
            throw new InvalidArgumentException("Unknown plan limit [{$key}].");
        }

        return max(0, config()->integer("billing.plans.{$this->value}.limits.{$key}"));
    }

    /**
     * Whether the plan allows a feature: `photo_entry`.
     *
     * @throws InvalidArgumentException When the key is not one of {@see self::FEATURES}.
     */
    public function allows(string $feature): bool
    {
        if (! in_array($feature, self::FEATURES, true)) {
            throw new InvalidArgumentException("Unknown plan feature [{$feature}].");
        }

        return config()->boolean("billing.plans.{$this->value}.features.{$feature}");
    }
}
