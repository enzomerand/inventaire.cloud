<?php

declare(strict_types=1);

namespace App\Installer;

use Illuminate\Foundation\Application;

/**
 * The cached configuration file (`php artisan config:cache`).
 *
 * Once the installer has written new settings to .env, a stale cache would keep
 * serving the old ones, so the finalizer removes it. It is a service of its own,
 * pointing at wherever the application keeps the cache (APP_CONFIG_CACHE), so
 * that tests clear their own file and never the real bootstrap/cache/config.php.
 */
final class ConfigCache
{
    public function __construct(private readonly Application $app) {}

    /**
     * Deletes the cached configuration, if there is one.
     */
    public function clear(): void
    {
        $path = $this->app->getCachedConfigPath();

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
