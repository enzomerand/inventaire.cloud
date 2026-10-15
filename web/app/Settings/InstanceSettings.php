<?php

declare(strict_types=1);

namespace App\Settings;

use Illuminate\Database\DatabaseManager;

/**
 * The settings an administrator chooses for the whole instance.
 *
 * They live in the `settings` table, one row per setting (a key and a JSON value), so that they change without
 * touching `.env` or restarting anything, and are the same for every process and every server that share the
 * database. A setting that has no row, or whose stored value is not of the right type (a row edited by hand), holds
 * its default: reading never fails because of what is stored.
 *
 * The rows are read once per request, all together (there are only a handful), and kept for the rest of it: asking
 * three times costs one query. Nothing is kept longer than the request, so a change made by an administrator is
 * seen by the very next request, wherever it is served; the price is one small query on the pages that need a
 * setting. The class is registered as a scoped service, so a worker that serves many requests starts afresh each time.
 * Writing a setting forgets what was read, and the next read loads it again.
 *
 * Each setting has a typed getter and setter, and a name here: nothing else knows how it is stored. The statements
 * are plain SQL builder calls that PostgreSQL, MySQL and MariaDB all run.
 */
final class InstanceSettings
{
    /** Whether anybody can create an account by signing in with a new address. */
    public const REGISTRATION_OPEN = 'registration_open';

    /** @var array<string, mixed>|null The stored values by key, or null until they are read. */
    private ?array $stored = null;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Whether a new address can sign in and so create an account (open unless an administrator closed it).
     * Accounts that exist are not concerned: closed or not, they sign in.
     */
    public function registrationOpen(): bool
    {
        $value = $this->read(self::REGISTRATION_OPEN);

        return is_bool($value) ? $value : true;
    }

    /**
     * Opens or closes the creation of accounts by new addresses.
     */
    public function setRegistrationOpen(bool $open): void
    {
        $this->write(self::REGISTRATION_OPEN, $open);
    }

    /**
     * Every setting with its current value, as the API shows them.
     *
     * @return array{registration_open: bool}
     */
    public function all(): array
    {
        return [self::REGISTRATION_OPEN => $this->registrationOpen()];
    }

    /**
     * The stored value of a setting, or null when there is none.
     */
    private function read(string $key): mixed
    {
        $this->stored ??= $this->load();

        return $this->stored[$key] ?? null;
    }

    /**
     * Reads every row of the table: key and decoded value. A value that is not valid JSON is skipped, which makes
     * the setting hold its default.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $values = [];

        foreach ($this->db->table('settings')->pluck('value', 'key') as $key => $json) {
            if (! is_string($json)) {
                continue;
            }

            $decoded = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $values[(string) $key] = $decoded;
            }
        }

        return $values;
    }

    /**
     * Stores a value (replacing the one the setting had) and forgets what was read.
     */
    private function write(string $key, mixed $value): void
    {
        $now = now();

        $this->db->table('settings')->upsert(
            [['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['value', 'updated_at'],
        );

        $this->stored = null;
    }
}
