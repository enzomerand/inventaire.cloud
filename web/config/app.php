<?php

declare(strict_types=1);
use App\Services\TrustedProxies;

return [

    /*
    |--------------------------------------------------------------------------
    | Search Engine Indexing
    |--------------------------------------------------------------------------
    |
    | Whether search engines may index the public pages (the home page and the
    | templates). Off by default: a development or staging copy must not end up
    | in a search engine. Off, /robots.txt disallows everything, every page asks
    | not to be indexed (`noindex`) and /sitemap.xml does not exist. On,
    | /robots.txt allows the public pages and keeps robots out of /app, /api,
    | /install and /login, /sitemap.xml lists the public pages in every language,
    | and the public pages ask to be indexed. The pages of the application itself
    | (signing in, your space, the API) always ask not to be.
    |
    | Read as a person writes a switch: 1, true, on and yes turn it on; anything
    | else (0, false, off, no, n, a typo) leaves it off, which is the safe side.
    |
    */

    'indexable' => filter_var(env('APP_INDEXABLE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone of PHP and of the database connections, which is where every
    | date is stored and computed. It is fixed to UTC and not configurable: a
    | stored date must not change meaning when the instance changes timezone.
    | The zone people see is `display_timezone`, below.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone of this instance, chosen in the installation wizard
    | (APP_TIMEZONE). It is for display only: dates are always stored and
    | exchanged in UTC (see `timezone`, above) and are converted to this zone
    | when the server writes one for people, such as the date in the name of
    | a download (see App\Support\DisplayTime). Pages show dates in the
    | timezone of the browser, so every time of day the server writes states
    | its zone (after the time in a text, in the column header of a sheet).
    |
    */

    'display_timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The reverse proxies whose X-Forwarded-* headers are believed, so that the
    | client address (throttling, rate limits) is the real one. A comma-separated
    | list of addresses or CIDR ranges, or "*" to trust every proxy. Empty by
    | default: trusting everybody lets any visitor forge their address.
    |
    */

    'trusted_proxies' => TrustedProxies::parse((string) env('TRUSTED_PROXIES', '')),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'fr'),

    /*
    | The language of the instance itself (APP_LOCALE), for what is written outside of any visitor's request: the mail
    | an administrator's new account receives, the language of that account. `locale` above is not that: the SetLocale
    | middleware replaces it with the language of every request (Application::setLocale() writes it back), so by the
    | time a controller runs it holds the visitor's language. This key is never written to.
    */

    'default_locale' => env('APP_LOCALE', 'fr'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'fr_FR'),

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The locales the interface is translated into, each with the name it is
    | displayed with in the language switcher. A language is always written in
    | itself (never translated) so that people can find theirs whatever the
    | current interface language is. The keys are the single source of truth for
    | which locales are supported (see App\Services\LocaleResolver::supported()),
    | so adding a language means adding it here and providing its
    | lang/<locale>.json file.
    |
    */

    'locale_names' => [
        'fr' => 'Français',
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Repository
    |--------------------------------------------------------------------------
    |
    | Public location of the source code this instance runs. The AGPL-3.0
    | (section 13) requires that users interacting with the application over a
    | network can obtain its source, so the page footer links here. Forks and
    | modified deployments must set APP_REPOSITORY_URL to where their own
    | source is published; the footer's licence link is derived from it.
    |
    */

    // "?:" rather than env()'s default: an empty APP_REPOSITORY_URL must fall back too.
    'repository_url' => env('APP_REPOSITORY_URL') ?: 'https://github.com/enzomerand/inventaire.cloud',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
