<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The parameters of the Stripe Checkout session that sells Pro to a workspace: a subscription (`mode`) of one unit of one Price, for a
 * customer that already exists, on a page Stripe hosts and brings the person back from.
 *
 * Two things are on purpose. `payment_method_types` is never sent: Stripe offers the methods enabled in the dashboard, which is how
 * wallets and local methods appear without a deployment. And the workspace is written in the session (`client_reference_id`) and in the
 * metadata of the subscription as a trace for a human reading the dashboard: the application never trusts them, it finds a workspace by
 * the customer id it stored.
 */
final class CheckoutParameters
{
    /** The languages Checkout is asked to speak; any other locale of the application leaves the choice to the browser (`auto`). */
    private const LOCALES = ['fr', 'en'];

    /**
     * The parameters of the session.
     *
     * @param  string  $customerId  The Stripe customer of the workspace (`cus_…`).
     * @param  string  $priceId  The Price to subscribe to (`price_…`), from {@see Billing::priceFor()}.
     * @param  string  $workspaceUlid  The ULID of the workspace that buys.
     * @param  string  $successUrl  Where Stripe brings the person back after paying.
     * @param  string  $cancelUrl  Where Stripe brings the person back if they leave the page.
     * @param  string  $locale  The language of the person (`fr`, `en`).
     * @return array<string, mixed>
     */
    public static function make(string $customerId, string $priceId, string $workspaceUlid, string $successUrl, string $cancelUrl, string $locale): array
    {
        return [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $workspaceUlid,
            'subscription_data' => ['metadata' => ['workspace' => $workspaceUlid]],
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'tax_id_collection' => ['enabled' => true],
            'customer_update' => ['name' => 'auto', 'address' => 'auto'],
            'automatic_tax' => ['enabled' => Billing::automaticTax()],
            'locale' => self::locale($locale),
            'integration_identifier' => Billing::integrationIdentifier(),
        ];
    }

    /**
     * The locale Stripe is asked for: the one of the person when Stripe's pages are written in it, else `auto`.
     */
    public static function locale(string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'auto';
    }
}
