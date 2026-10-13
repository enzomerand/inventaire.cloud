<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Exceptions\InstallationException;
use App\Installer\Support\AtomicFile;
use Illuminate\Contracts\Session\Session;

/**
 * The one-time secret that gates the web wizard.
 *
 * Until an administrator exists, anybody who can reach /install could
 * configure the instance (and point it at their own database). The token proves
 * whoever opens the wizard also has access to the server: it is only available
 * in the application log and through `php artisan inventaire:install-token`.
 *
 * It is stored in clear text, owner-readable only, because the server must
 * show it again on request; a hash would be of no use. It disappears when the
 * installation completes.
 */
final class SetupToken
{
    private const FILE = 'setup-token';

    /** Session key remembering which token a browser entered. */
    private const SESSION_KEY = 'installer.token';

    public function __construct(private readonly InstallationState $state) {}

    /**
     * Makes sure a token exists, creating it when needed.
     *
     * @return bool True when the token had to be created by this call.
     *
     * @throws InstallationException When the token cannot be stored.
     */
    public function ensure(): bool
    {
        if ($this->read() !== null) {
            return false;
        }

        return $this->state->exclusively(function (): bool {
            if ($this->read() !== null) {
                return false;
            }

            AtomicFile::write($this->path(), bin2hex(random_bytes(16))."\n", 0600);

            return true;
        });
    }

    /**
     * The current token, created if it does not exist yet.
     *
     * @throws InstallationException When the token cannot be stored.
     */
    public function current(): string
    {
        $this->ensure();

        return $this->read() ?? throw new InstallationException('Cannot read the setup token.');
    }

    /**
     * Checks a submitted token in constant time.
     */
    public function verify(string $candidate): bool
    {
        $token = $this->read();
        $candidate = trim($candidate);

        return $token !== null && $candidate !== '' && hash_equals($token, $candidate);
    }

    /**
     * A digest of the token, kept in the session of an authorized browser.
     *
     * Comparing it on every request ties the authorization to the token that
     * was entered: once the token is deleted or replaced, old sessions stop
     * working.
     */
    public function fingerprint(): ?string
    {
        $token = $this->read();

        return $token === null ? null : hash('sha256', $token);
    }

    /**
     * Marks a browser session as authorized by the token it just entered.
     *
     * The session id is regenerated at this privilege change so an id known
     * before the token was entered (fixation) is worthless afterwards.
     */
    public function authorize(Session $session): void
    {
        $session->regenerate();
        $session->put(self::SESSION_KEY, $this->fingerprint());
    }

    /**
     * Whether a browser session entered the token that is still current.
     */
    public function isAuthorized(Session $session): bool
    {
        $stored = $session->get(self::SESSION_KEY);
        $fingerprint = $this->fingerprint();

        return $fingerprint !== null && is_string($stored) && hash_equals($fingerprint, $stored);
    }

    /**
     * Deletes the token (the installation is over).
     */
    public function forget(): void
    {
        @unlink($this->path());
        clearstatcache(true, $this->path());
    }

    /**
     * Where the token is stored.
     */
    public function path(): string
    {
        return $this->state->path(self::FILE);
    }

    /**
     * Reads the stored token, or null when there is none.
     */
    private function read(): ?string
    {
        clearstatcache(true, $this->path());

        $contents = @file_get_contents($this->path());
        $token = $contents === false ? '' : trim($contents);

        return $token === '' ? null : $token;
    }
}
