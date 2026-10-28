<?php

declare(strict_types=1);

namespace App\Billing;

use App\Signatures\Documenso;

/**
 * What the instance is configured to do with Stripe: whether workspaces can buy a plan, which Prices they buy, whether the webhook is
 * listened to, and what the Pro plan is shown to cost.
 *
 * Everything comes from `services.stripe` (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_PRO_YEARLY`)
 * and `config/billing.php`. An instance without Stripe is complete: billing is off and every quota is the one of the instance (see
 * {@see WorkspaceLimits}). Like {@see Documenso}, the values are read each time, never kept, so that a test (or an administrator who
 * edits the environment file and restarts the workers) is not served a stale answer.
 */
final class Billing
{
    /** The billing intervals a plan can be bought for, as Stripe and the API name them. */
    public const INTERVALS = ['month', 'year'];

    /**
     * Whether workspaces can buy a plan: the secret key and the Price of the monthly plan are both set. Everything else (the quotas, the
     * billing page, the checkout) hangs from this one answer.
     */
    public static function enabled(): bool
    {
        return self::setting('secret') !== '' && self::setting('prices.monthly') !== '';
    }

    /**
     * Whether the webhook is open: billing is on and a signing secret is set. Without one `/webhooks/stripe` does not exist (404), and the
     * subscriptions are synchronised by the schedule alone (an instance behind a NAT, or a local one, has no webhook).
     */
    public static function webhookEnabled(): bool
    {
        return self::enabled() && self::webhookSecret() !== '';
    }

    /**
     * The secret key of the Stripe account, sent in every call. Never to be written in a log, an answer or an exception message.
     */
    public static function secret(): string
    {
        return self::setting('secret');
    }

    /**
     * The signing secret (`whsec_…`) of the webhook endpoint, which signs what Stripe sends; an empty text when there is none.
     */
    public static function webhookSecret(): string
    {
        return self::setting('webhook_secret');
    }

    /**
     * Whether the secret key is one of the live mode of Stripe, and not of its test mode (`sk_test_…`, `rk_test_…`): which side of the
     * dashboard a link to an object of Stripe opens.
     */
    public static function livemode(): bool
    {
        return ! str_contains(self::secret(), '_test_');
    }

    /**
     * The version of the Stripe API the code is written for, sent in the `Stripe-Version` header of every call.
     */
    public static function apiVersion(): string
    {
        return self::setting('api_version');
    }

    /**
     * The root of the Stripe API, without a trailing slash.
     */
    public static function baseUrl(): string
    {
        return rtrim(self::setting('base_url'), '/');
    }

    /**
     * The Stripe Price of Pro for an interval; null when there is none (no yearly Price is set, or the interval is unknown).
     *
     * @param  string  $interval  `month` or `year`.
     */
    public static function priceFor(string $interval): ?string
    {
        $price = match ($interval) {
            'month' => self::setting('prices.monthly'),
            'year' => self::setting('prices.yearly'),
            default => '',
        };

        return $price === '' ? null : $price;
    }

    /**
     * The intervals a workspace can buy Pro for: the month when billing is on, and the year when a yearly Price is set too; none when
     * billing is off.
     *
     * @return list<string>
     */
    public static function intervals(): array
    {
        return array_values(array_filter(
            self::INTERVALS,
            fn (string $interval): bool => self::enabled() && self::priceFor($interval) !== null,
        ));
    }

    /**
     * What Pro is shown to cost: the currency (lowercase ISO code) and the amount of each interval in the smallest unit of the currency
     * (cents); the year is null when no yearly Price is set. Display only, the amount charged is the one of the Stripe Price.
     *
     * @return array{currency: string, month: int, year: int|null}
     */
    public static function display(): array
    {
        return [
            'currency' => config()->string('billing.currency'),
            'month' => config()->integer('billing.amounts.month'),
            'year' => self::priceFor('year') === null ? null : config()->integer('billing.amounts.year'),
        ];
    }

    /**
     * Whether Stripe Checkout works the tax out (`STRIPE_AUTOMATIC_TAX`).
     */
    public static function automaticTax(): bool
    {
        return config()->boolean('billing.automatic_tax');
    }

    /**
     * The identifier this integration gives Stripe Checkout.
     */
    public static function integrationIdentifier(): string
    {
        return config()->string('billing.integration_identifier');
    }

    /**
     * A value of `services.stripe`, trimmed; an empty text when it is not set.
     */
    private static function setting(string $key): string
    {
        $value = config("services.stripe.{$key}");

        return is_string($value) ? trim($value) : '';
    }
}
