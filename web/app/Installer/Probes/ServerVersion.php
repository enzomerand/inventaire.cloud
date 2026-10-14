<?php

declare(strict_types=1);

namespace App\Installer\Probes;

/**
 * What a database server says about itself, normalized.
 *
 * MySQL and MariaDB share one wire protocol and one PHP driver, and a MariaDB
 * server answers to a "mysql" connection, so the engine can only be told apart
 * by the version string ("10.11.6-MariaDB-1:10.11.6+maria~ubu2204", sometimes
 * prefixed with a fake "5.5.5-" for protocol compatibility).
 */
final readonly class ServerVersion
{
    /**
     * @param  string  $flavor  pgsql, mysql or mariadb: the engine actually running.
     * @param  string  $version  Dotted numeric version, e.g. "17.2" or "10.11.6".
     */
    private function __construct(public string $flavor, public string $version) {}

    /**
     * Interprets the version string reported by PDO.
     *
     * @param  string  $driver  The Laravel driver used to connect (pgsql, mysql or mariadb).
     * @param  string  $reported  PDO::ATTR_SERVER_VERSION.
     */
    public static function parse(string $driver, string $reported): self
    {
        if ($driver === 'pgsql') {
            return new self('pgsql', self::numeric($reported));
        }

        if (stripos($reported, 'mariadb') !== false) {
            return new self('mariadb', self::numeric((string) preg_replace('/^5\.5\.5-/', '', $reported)));
        }

        return new self('mysql', self::numeric($reported));
    }

    /**
     * Human name of the engine.
     */
    public function engine(): string
    {
        return match ($this->flavor) {
            'pgsql' => 'PostgreSQL',
            'mariadb' => 'MariaDB',
            default => 'MySQL',
        };
    }

    private static function numeric(string $reported): string
    {
        return preg_match('/\d+(?:\.\d+)*/', $reported, $match) === 1 ? $match[0] : '0';
    }
}
