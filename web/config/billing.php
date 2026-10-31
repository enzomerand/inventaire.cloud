<?php

declare(strict_types=1);

use App\Billing\EnvironmentSetting;

/*
|--------------------------------------------------------------------------
| Billing (Stripe subscriptions, one per workspace)
|--------------------------------------------------------------------------
|
| Billing is off unless the instance has a Stripe secret key and the Price of
| the monthly Pro plan (`STRIPE_SECRET`, `STRIPE_PRICE_PRO_MONTHLY`, see
| `services.stripe`): a self-hosted instance that sets neither keeps the quotas
| of `config/inventories.php` and `config/workspaces.php` exactly as they are.
|
| When it is on, a workspace is on the Free plan, or on Pro while its
| subscription is active, trialing or past due (`App\Billing\WorkspacePlan`),
| and the limits below replace the instance-wide ones for that workspace
| (`App\Billing\WorkspaceLimits`). The per-user limits (exports, imports,
| tokens, photo analyses per day) stay global.
|
| A variable left blank (`BILLING_PRO_ITEMS=`) is a variable that is not set:
| the default below applies (`App\Billing\EnvironmentSetting`). To allow
| nothing, write `0`.
|
*/

return [

    /*
    | Shows the plans and the terms of sale to visitors before anything can be
    | bought (`/pricing`, the pricing section of the home page, `/terms`), with
    | a disabled "Coming soon" button for Pro. Nothing is sold: checkout stays
    | closed until billing is on and the legal identity of the seller is
    | complete. Ignored once the instance sells.
    */
    'pricing_preview' => EnvironmentSetting::boolean(env('BILLING_PRICING_PREVIEW'), false),

    /*
    | Sends `automatic_tax[enabled]` to Stripe Checkout (Stripe Tax must be set
    | up in the Stripe account, with the tax registrations that apply). The
    | prices are VAT-included whatever this says: the Stripe Prices must be
    | created with `tax_behavior=inclusive`.
    */
    'automatic_tax' => EnvironmentSetting::boolean(env('STRIPE_AUTOMATIC_TAX'), false),

    /*
    | The identifier this integration gives Stripe Checkout
    | (`integration_identifier`).
    */
    'integration_identifier' => 'inventaire-cloud-pkqzrvmw',

    /*
    | What the page shows as the price of Pro, in the smallest unit of the
    | currency (cents). Display only: the amount charged is the one of the
    | Stripe Price (`STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_PRO_YEARLY`), keep
    | the two in step. The yearly amount is shown only when a yearly Price is set.
    */
    'currency' => strtolower(EnvironmentSetting::text(env('BILLING_CURRENCY'), 'eur')),

    'amounts' => [
        'month' => EnvironmentSetting::integer(env('BILLING_PRO_MONTHLY_AMOUNT'), 600),
        'year' => EnvironmentSetting::integer(env('BILLING_PRO_YEARLY_AMOUNT'), 6000),
    ],

    /*
    | The limits of each plan, per workspace:
    |
    | - `inventories`: inventories in the workspace;
    | - `items`: items of one inventory;
    | - `storage_bytes`: bytes the files of the workspace may weigh (the
    |   variables are in megabytes);
    | - `members`: members of the workspace together with the invitations that
    |   are waiting;
    | - `share_links`: links one inventory may have that still work;
    | - `photo_entry`: whether the workspace may use the photo entry (it still
    |   needs the administrator's switch and the owner's consent).
    */
    'plans' => [
        'free' => [
            'limits' => [
                'inventories' => EnvironmentSetting::integer(env('BILLING_FREE_INVENTORIES'), 3),
                'items' => EnvironmentSetting::integer(env('BILLING_FREE_ITEMS'), 500),
                'storage_bytes' => (int) round(EnvironmentSetting::number(env('BILLING_FREE_STORAGE_MB'), 200) * 1048576),
                'members' => EnvironmentSetting::integer(env('BILLING_FREE_MEMBERS'), 3),
                'share_links' => EnvironmentSetting::integer(env('BILLING_FREE_SHARE_LINKS'), 2),
            ],
            'features' => [
                'photo_entry' => EnvironmentSetting::boolean(env('BILLING_FREE_PHOTO_ENTRY'), false),
            ],
        ],

        'pro' => [
            'limits' => [
                'inventories' => EnvironmentSetting::integer(env('BILLING_PRO_INVENTORIES'), 100),
                'items' => EnvironmentSetting::integer(env('BILLING_PRO_ITEMS'), 10000),
                'storage_bytes' => (int) round(EnvironmentSetting::number(env('BILLING_PRO_STORAGE_MB'), 10240) * 1048576),
                'members' => EnvironmentSetting::integer(env('BILLING_PRO_MEMBERS'), 50),
                'share_links' => EnvironmentSetting::integer(env('BILLING_PRO_SHARE_LINKS'), 20),
            ],
            'features' => [
                'photo_entry' => EnvironmentSetting::boolean(env('BILLING_PRO_PHOTO_ENTRY'), true),
            ],
        ],
    ],

];
