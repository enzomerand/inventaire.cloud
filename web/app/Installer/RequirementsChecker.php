<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Support\AtomicFile;
use Closure;
use Illuminate\Foundation\Application;

/**
 * Checks that the server can run the application before anything is configured.
 *
 * The PHP version is read from composer.json so the installer never drifts
 * from what `composer install` enforces.
 */
final class RequirementsChecker
{
    /** Extensions the application cannot run without. */
    private const EXTENSIONS = ['mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo', 'intl', 'zip', 'curl'];

    /** @var Closure(string): bool */
    private readonly Closure $extensionLoaded;

    /**
     * @param  Closure(string): bool|null  $extensionLoaded  Tells whether a PHP extension is loaded; defaults to extension_loaded().
     * @param  string|null  $phpVersion  The running PHP version; defaults to PHP_VERSION.
     */
    public function __construct(
        private readonly Application $app,
        private readonly EnvironmentFile $environment,
        private readonly InstallerSettings $settings,
        ?Closure $extensionLoaded = null,
        private readonly ?string $phpVersion = null,
    ) {
        $this->extensionLoaded = $extensionLoaded ?? extension_loaded(...);
    }

    /**
     * Runs every check.
     *
     * @return list<Requirement>
     */
    public function check(): array
    {
        $requirements = [$this->php()];

        $requirements[] = $this->extension('pdo');
        $requirements[] = $this->anyOf(
            'database-driver',
            __('Database driver: PostgreSQL (pdo_pgsql) or MySQL / MariaDB (pdo_mysql)'),
            ['pdo_pgsql', 'pdo_mysql'],
        );

        foreach (self::EXTENSIONS as $extension) {
            $requirements[] = $this->extension($extension);
        }

        $requirements[] = $this->anyOf('image-library', __('Image library: GD (gd) or Imagick (imagick)'), ['gd', 'imagick']);

        $requirements[] = $this->writable('storage', __('Writable folder: :path', ['path' => 'storage/']), $this->app->storagePath(), is_dir($this->app->storagePath()) && is_writable($this->app->storagePath()));

        // A container configured only through variables writes neither .env nor the framework caches.
        $notNeeded = $this->settings->isEnvironmentOnly();

        $requirements[] = $this->writable('bootstrap-cache', __('Writable folder: :path', ['path' => 'bootstrap/cache/']), $this->app->bootstrapPath('cache'), is_dir($this->app->bootstrapPath('cache')) && is_writable($this->app->bootstrapPath('cache')), $notNeeded);
        $requirements[] = $this->writable('env', __('Writable file: :path', ['path' => '.env']), $this->environment->path(), $this->environment->isWritable(), $notNeeded);

        return $requirements;
    }

    /**
     * Whether every requirement is met.
     *
     * @param  list<Requirement>  $requirements
     */
    public static function passed(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if (! $requirement->ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * The minimum PHP version required by composer.json (`require.php`), e.g.
     * "8.3" for "^8.3", or null when it cannot be read.
     */
    public function minimumPhpVersion(): ?string
    {
        $contents = @file_get_contents($this->app->basePath('composer.json'));
        $composer = $contents === false ? null : json_decode($contents, true);
        $constraint = is_array($composer) && is_array($composer['require'] ?? null) ? ($composer['require']['php'] ?? null) : null;

        if (! is_string($constraint) || preg_match('/(\d+(?:\.\d+){0,2})/', $constraint, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function php(): Requirement
    {
        $found = $this->phpVersion ?? PHP_VERSION;
        $minimum = $this->minimumPhpVersion();

        if ($minimum === null) {
            return new Requirement('php', __('PHP version'), true, __('Running PHP :version (composer.json could not be read to compare).', ['version' => $found]));
        }

        $ok = version_compare($found, $minimum, '>=');

        return new Requirement(
            'php',
            __('PHP :version or newer', ['version' => $minimum]),
            $ok,
            $ok ? __('Running PHP :version.', ['version' => $found]) : __('Running PHP :version: upgrade PHP.', ['version' => $found]),
        );
    }

    private function extension(string $name): Requirement
    {
        $label = __('PHP extension: :name', ['name' => $name]);

        if (($this->extensionLoaded)($name)) {
            return new Requirement('ext-'.$name, $label, true);
        }

        return new Requirement('ext-'.$name, $label, false, __('Install or enable the :name extension.', ['name' => $name]));
    }

    /**
     * @param  list<string>  $extensions  At least one must be loaded.
     */
    private function anyOf(string $id, string $label, array $extensions): Requirement
    {
        $loaded = array_values(array_filter($extensions, fn (string $name): bool => ($this->extensionLoaded)($name)));

        return new Requirement(
            $id,
            $label,
            $loaded !== [],
            $loaded !== [] ? __('Available: :list.', ['list' => implode(', ', $loaded)]) : __('Install or enable at least one of: :list.', ['list' => implode(', ', $extensions)]),
        );
    }

    private function writable(string $id, string $label, string $path, bool $ok, bool $notNeeded = false): Requirement
    {
        if ($ok) {
            return new Requirement($id, $label, true);
        }

        if ($notNeeded) {
            return new Requirement($id, $label, true, __('Not needed: every setting is provided by the environment.'));
        }

        return new Requirement($id, $label, false, __('The web server user cannot write to :path. Change its permissions or ownership.', ['path' => AtomicFile::display($path)]));
    }
}
