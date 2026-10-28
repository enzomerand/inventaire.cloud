<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Documenso, the e-signature service used to sign official versions of an
    | inventory. The URL is the instance root (https://app.documenso.com or a
    | self-hosted one); the API key is a team token created in Documenso. The
    | webhook secret is the one Documenso is told to send in the
    | `X-Documenso-Secret` header of its calls to `/webhooks/documenso`; without
    | it the webhook answers 404 and the signatures are only synchronised by the
    | schedule. All three are optional.
    */
    'documenso' => [
        'url' => env('DOCUMENSO_URL'),
        'api_key' => env('DOCUMENSO_API_KEY'),
        'webhook_secret' => env('DOCUMENSO_WEBHOOK_SECRET'),
    ],

    /*
    | Stripe, which sells the Pro plan of a workspace (App\Billing). Billing is on
    | only when the secret key and the Price of the monthly plan are both set; the
    | yearly Price is optional. The webhook secret is the signing secret (`whsec_…`)
    | of the endpoint `/webhooks/stripe`; without it the webhook answers 404 and the
    | subscriptions are only synchronised by the schedule. The secret key stays in the
    | environment, never in the database nor in a log. `api_version` is the version
    | of the Stripe API the code is written for, sent in the `Stripe-Version` header.
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'prices' => [
            'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
            'yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
        ],
        'api_version' => '2026-09-30.endive',
        'base_url' => 'https://api.stripe.com',
    ],

    // The photo entry (App\PhotoEntry): Claude reads the photos of an object and proposes the values of its fields. The key stays in
    // the environment, never in the database nor in a log; without it the feature is unavailable whatever the admin switch says.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'low'),
        // Seconds a request may take. ClaudePhotoAnalyzer holds it between 10 and 120 whatever is set here: the job that runs the analysis is
        // killed after 300 seconds, and a request may be retried once.
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 90),
        // Whether the models that accept it are sent the server-side fallback (a request the model declines for policy reasons is retried on
        // a substitute, which means a dated beta header). false stops sending it without changing the model; unset or empty is the default (on).
        'fallbacks' => in_array(env('ANTHROPIC_FALLBACKS'), [null, ''], true) || filter_var(env('ANTHROPIC_FALLBACKS'), FILTER_VALIDATE_BOOLEAN),
        // Development aid: answers with made-up proposals instead of calling Claude. Honoured only when APP_ENV is local.
        'fake' => (bool) env('ANTHROPIC_FAKE', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
