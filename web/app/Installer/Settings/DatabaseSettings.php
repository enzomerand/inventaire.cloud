<?php

declare(strict_types=1);

namespace App\Installer\Settings;

/**
 * Connection details of the application database.
 */
final readonly class DatabaseSettings
{
    /** Engines the application supports (the Laravel driver names). */
    public const DRIVERS = ['pgsql', 'mysql', 'mariadb'];

    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public ?string $url = null,
    ) {}

    /**
     * Builds the settings from the values of the database step.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $url  The connection URL (DB_URL) when one is provided; it then replaces the other values.
     */
    public static function fromArray(array $values, ?string $url = null): self
    {
        return new self(
            driver: self::text($values, 'driver'),
            host: self::text($values, 'host'),
            port: (int) self::text($values, 'port'),
            database: self::text($values, 'database'),
            username: self::text($values, 'username'),
            password: self::text($values, 'password'),
            url: $url,
        );
    }

    /**
     * A Laravel connection configuration targeting these settings.
     *
     * The engine's own entry in config/database.php is the base, so charset,
     * collation, schema and SSL options stay what the application uses. With
     * a connection URL the fields are ignored, exactly as Laravel does: the URL
     * is handed over as is and parsed when the connection is opened.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function connectionConfig(array $base): array
    {
        if ($this->url !== null) {
            return array_replace($base, ['url' => $this->url]);
        }

        return array_replace($base, [
            'url' => null,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function text(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
