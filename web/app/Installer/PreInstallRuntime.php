<?php

declare(strict_types=1);

namespace App\Installer;

use Illuminate\Contracts\Config\Repository;

/**
 * Lets the application run before it is configured.
 *
 * A new instance has no database yet, and the default session, cache and
 * queue drivers all live in the database. Until the installation completes,
 * they are replaced at runtime by drivers that need nothing: sessions and
 * cache on files, jobs run inline. Nothing is written to .env for this; the
 * configured drivers apply again as soon as the instance is installed.
 */
final class PreInstallRuntime
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Applies the overrides to the running configuration.
     */
    public function apply(): void
    {
        $this->config->set('session.driver', 'file');
        $this->config->set('cache.default', 'file');
        $this->config->set('queue.default', 'sync');
    }
}
