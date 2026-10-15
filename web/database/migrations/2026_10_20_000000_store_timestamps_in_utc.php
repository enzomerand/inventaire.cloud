<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stores every date in UTC. Until now they were stored in the timezone of the instance (`APP_TIMEZONE`), except those
 * of the administrator made by the installer, which were in UTC: stored dates did not mean the same thing from one row
 * to the next, nor after a change of timezone. From now on `app.timezone` and the database connections are UTC, and the
 * timezone of the instance (`app.display_timezone`) is only used to display.
 *
 * This converts the dates already stored from the display timezone to UTC. When that timezone is UTC there is nothing
 * to do, and the migration does nothing. The administrator made by the installer was already in UTC and is converted
 * with the others: its dates are shifted by the offset of the timezone. This is accepted, as telling its rows apart
 * would take a guess and the dates are those of an account's creation.
 *
 * The pending sign-in and account deletion codes are deleted rather than converted: they live for minutes, and a person
 * who is waiting for one asks for another. The reverse migration deletes them as well, since its dates would be
 * misread by the old code.
 *
 * MySQL and MariaDB keep a `timestamp` column as an instant and convert it with the timezone of the session on the way
 * in and out. The old code did not set that zone, so the server's own default was used. The migration reads with that
 * session (it sees the dates as the old code did, which is what is to be converted) and writes with the UTC one, then
 * puts the UTC session back. On a server whose default zone is not UTC, this also corrects the dates of an instance
 * whose display timezone is UTC. PostgreSQL's columns hold no zone, and nothing is needed for them.
 *
 * A local time that does not exist, or exists twice, because of a change of hour (a few hours a year) is read as PHP
 * reads it: the first time it happened.
 *
 * Everything runs in one transaction, and uses the tables directly rather than the models (which will evolve, while
 * this migration must keep doing what it did) and without events.
 */
return new class extends Migration
{
    /**
     * The session timezone of the old code: the default of the database server.
     */
    private const LEGACY = 'legacy';

    /**
     * The session timezone of the new code, set in config/database.php.
     */
    private const UTC = 'utc';

    /**
     * The format of a date in a datetime column, whatever the engine.
     */
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * The datetime columns to convert, by table. `failed_jobs.failed_at` is left out, as it holds the clock of the
     * database server, and so are the columns that hold Unix times (sessions, cache, jobs).
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'users' => ['email_verified_at', 'created_at', 'updated_at'],
        'personal_access_tokens' => ['expires_at', 'last_used_at', 'created_at', 'updated_at'],
        'workspaces' => ['created_at', 'updated_at'],
        'workspace_user' => ['created_at', 'updated_at'],
        'settings' => ['created_at', 'updated_at'],
    ];

    /**
     * The tables of short-lived codes, which are emptied rather than converted.
     *
     * @var list<string>
     */
    private const CODES = ['login_codes', 'account_deletion_codes'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $display = $this->displayTimezone();

        if ($this->isUtc($display) && ! $this->serverZoneIsNotUtc()) {
            return;
        }

        $this->convert($display, 'UTC', read: self::LEGACY, write: self::UTC);
    }

    /**
     * Reverse the migrations: the dates go back to the display timezone, which is the one the old code expects.
     *
     * The sign-in and account deletion codes are not restored.
     */
    public function down(): void
    {
        $display = $this->displayTimezone();

        if ($this->isUtc($display) && ! $this->serverZoneIsNotUtc()) {
            return;
        }

        $this->convert('UTC', $display, read: self::UTC, write: self::LEGACY);
    }

    /**
     * Converts every date from a timezone to another, and empties the tables of codes.
     *
     * @param  string  $from  The timezone the stored dates are in.
     * @param  string  $to  The timezone they are to be in.
     * @param  string  $read  The session timezone to read the rows with.
     * @param  string  $write  The session timezone to write them with.
     */
    private function convert(string $from, string $to, string $read, string $write): void
    {
        try {
            DB::transaction(function () use ($from, $to, $read, $write): void {
                foreach (self::CODES as $table) {
                    DB::table($table)->delete();
                }

                foreach (self::COLUMNS as $table => $columns) {
                    $this->convertTable($table, $columns, $from, $to, $read, $write);
                }
            });
        } finally {
            $this->useSession(self::UTC);
        }
    }

    /**
     * Converts the dates of a table, a chunk of rows at a time.
     *
     * @param  list<string>  $columns
     */
    private function convertTable(string $table, array $columns, string $from, string $to, string $read, string $write): void
    {
        // The settings are keyed by their name; every other table by a number.
        $key = $table === 'settings' ? 'key' : 'id';

        $this->useSession($read);

        DB::table($table)->select([$key, ...$columns])->chunkById(200, function ($rows) use ($table, $columns, $key, $from, $to, $read, $write): void {
            $this->useSession($write);

            foreach ($rows as $row) {
                $changes = [];

                foreach ($columns as $column) {
                    if (is_string($row->{$column})) {
                        $changes[$column] = CarbonImmutable::parse($row->{$column}, $from)->setTimezone($to)->format(self::FORMAT);
                    }
                }

                if ($changes !== []) {
                    DB::table($table)->where($key, $row->{$key})->update($changes);
                }
            }

            // The next chunk is read with the session that reads.
            $this->useSession($read);
        }, $key);
    }

    /**
     * The timezone the stored dates are in, which is the one the instance displays them in.
     */
    private function displayTimezone(): string
    {
        return config()->string('app.display_timezone');
    }

    /**
     * Whether a timezone is UTC itself, in which case converting from or to it changes nothing.
     */
    private function isUtc(string $timezone): bool
    {
        return strcasecmp($timezone, 'UTC') === 0;
    }

    /**
     * Whether the old code stored its dates with a session timezone other than UTC, which only MySQL and MariaDB can
     * do, with the default zone of their server. It is told by asking both sessions what a winter and a summer date
     * are in Unix time: they differ when the server's zone is not UTC at one of the two seasons.
     */
    private function serverZoneIsNotUtc(): bool
    {
        if (! $this->usesSessionTimezone()) {
            return false;
        }

        $probe = static fn (): array => (array) DB::selectOne("select unix_timestamp('2026-01-15 12:00:00') as winter, unix_timestamp('2026-07-15 12:00:00') as summer");

        $this->useSession(self::LEGACY);
        $legacy = $probe();
        $this->useSession(self::UTC);

        return $legacy != $probe();
    }

    /**
     * Sets the timezone of the database session, where the engine has one that matters.
     *
     * @param  string  $session  {@see self::LEGACY} or {@see self::UTC}.
     */
    private function useSession(string $session): void
    {
        if ($this->usesSessionTimezone()) {
            DB::statement($session === self::LEGACY ? 'set time_zone = @@global.time_zone' : "set time_zone = '+00:00'");
        }
    }

    /**
     * Whether the engine converts its `timestamp` columns with the timezone of the session (MySQL and MariaDB).
     */
    private function usesSessionTimezone(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
