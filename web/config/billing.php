<?php

declare(strict_types=1);

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
*/

return [

    /*
    | Sends `automatic_tax[enabled]` to Stripe Checkout (Stripe Tax must be set
    | up in the Stripe account, with the tax registrations that apply).
    */
    'automatic_tax' => (bool) env('STRIPE_AUTOMATIC_TAX', false),

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
    'currency' => strtolower((string) env('BILLING_CURRENCY', 'eur')),

    'amounts' => [
        'month' => (int) env('BILLING_PRO_MONTHLY_AMOUNT', 600),
        'year' => (int) env('BILLING_PRO_YEARLY_AMOUNT', 6000),
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
                'inventories' => (int) env('BILLING_FREE_INVENTORIES', 3),
                'items' => (int) env('BILLING_FREE_ITEMS', 500),
                'storage_bytes' => (int) round((float) env('BILLING_FREE_STORAGE_MB', 200) * 1048576),
                'members' => (int) env('BILLING_FREE_MEMBERS', 3),
                'share_links' => (int) env('BILLING_FREE_SHARE_LINKS', 2),
            ],
            'features' => [
                'photo_entry' => filter_var(env('BILLING_FREE_PHOTO_ENTRY', false), FILTER_VALIDATE_BOOLEAN),
            ],
        ],

        'pro' => [
            'limits' => [
                'inventories' => (int) env('BILLING_PRO_INVENTORIES', 100),
                'items' => (int) env('BILLING_PRO_ITEMS', 10000),
                'storage_bytes' => (int) round((float) env('BILLING_PRO_STORAGE_MB', 10240) * 1048576),
                'members' => (int) env('BILLING_PRO_MEMBERS', 50),
                'share_links' => (int) env('BILLING_PRO_SHARE_LINKS', 20),
            ],
            'features' => [
                'photo_entry' => filter_var(env('BILLING_PRO_PHOTO_ENTRY', true), FILTER_VALIDATE_BOOLEAN),
            ],
        ],
    ],

];
