<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;

/**
 * Creates the application key the first time the instance runs.
 *
 * Sessions and the wizard's encrypted values need APP_KEY, and a fresh
 * checkout (or a Docker image) has none. Instead of asking for a manual
 * `key:generate`, the key is generated on first access and stored in .env.
 */
final class ApplicationKey
{
    public function __construct(
        private readonly EnvironmentFile $environment,
        private readonly InstallationState $state,
        private readonly Repository $config,
    ) {}

    /**
     * Whether the running configuration has no usable key.
     */
    public function isMissing(): bool
    {
        $key = $this->config->get('app.key');

        return ! is_string($key) || $key === '';
    }

    /**
     * Generates and stores a key if none is configured, and makes it
     * available to the current request.
     *
     * Two requests arriving together on a fresh instance must end up with the
     * same key, so the check and the write happen under a lock and the file is
     * consulted again once it is held.
     *
     * A .env created here starts in production mode (see {@see EnvironmentFile::NEW_FILE_DEFAULTS}).
     *
     * @return string The key now in use.
     *
     * @throws InstallationException When .env is not writable.
     */
    public function ensure(): string
    {
        if (! $this->isMissing()) {
            return $this->config->string('app.key');
        }

        if (! $this->environment->isWritable()) {
            throw new InstallationException('The application key is missing and the .env file is not writable. Set APP_KEY in the environment, or make the .env file writable.');
        }

        $key = $this->state->exclusively(function (): string {
            $stored = $this->environment->get('APP_KEY');

            if ($stored !== null && $stored !== '') {
                return $stored;
            }

            $key = 'base64:'.base64_encode(Encrypter::generateKey($this->config->string('app.cipher')));
            $this->environment->set(['APP_KEY' => $key], EnvironmentFile::NEW_FILE_DEFAULTS);

            return $key;
        });

        $this->config->set('app.key', $key);

        return $key;
    }
}
