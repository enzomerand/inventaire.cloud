<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The dates the migration converts, as they are stored in Europe/Paris and as they must become in UTC.
 *
 * One date per column, summer and winter time mixed (Paris is 2 hours ahead of UTC in summer, 1 in winter), some that
 * change the day, and some that are empty.
 *
 * @return array<string, array<string, array{0: ?string, 1: ?string}>> Table => column => [stored in Paris, in UTC].
 */
function parisDates(): array
{
    return [
        'users' => [
            'email_verified_at' => ['2026-07-01 12:00:00', '2026-07-01 10:00:00'],
            'created_at' => ['2026-01-15 12:00:00', '2026-01-15 11:00:00'],
            'updated_at' => ['2026-10-24 23:30:00', '2026-10-24 21:30:00'],
        ],
        'personal_access_tokens' => [
            'last_used_at' => [null, null],
            'expires_at' => ['2027-01-15 08:00:00', '2027-01-15 07:00:00'],
            'created_at' => ['2026-07-14 00:30:00', '2026-07-13 22:30:00'],
            'updated_at' => ['2026-12-31 23:59:59', '2026-12-31 22:59:59'],
        ],
        'workspaces' => [
            'created_at' => ['2026-01-02 00:15:00', '2026-01-01 23:15:00'],
            'updated_at' => ['2026-06-02 00:15:00', '2026-06-01 22:15:00'],
        ],
        'workspace_user' => [
            'created_at' => ['2026-03-28 12:00:00', '2026-03-28 11:00:00'],
            'updated_at' => ['2026-03-30 12:00:00', '2026-03-30 10:00:00'],
        ],
        'settings' => [
            'created_at' => ['2026-11-30 18:45:00', '2026-11-30 17:45:00'],
            'updated_at' => ['2026-08-31 18:45:00', '2026-08-31 16:45:00'],
        ],
    ];
}

/**
 * One row in every table the migration touches, and a pending code in each table it empties, then the given dates,
 * as stored in the local timezone, written over the dates of those rows.
 *
 * @param  array<string, array<string, array{0: ?string, 1: ?string}>>  $dates
 */
function seedDatedRows(array $dates): void
{
    $user = User::factory()->withPersonalWorkspace()->create();
    $now = now();

    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'Script', 'token' => str_repeat('a', 64),
        'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('settings')->insert(['key' => 'registration_open', 'value' => 'true', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('login_codes')->insert(['email' => 'visitor@example.test', 'code_hash' => str_repeat('b', 64), 'expires_at' => $now, 'created_at' => $now]);
    DB::table('account_deletion_codes')->insert(['user_id' => $user->id, 'code_hash' => str_repeat('c', 64), 'expires_at' => $now, 'created_at' => $now]);

    foreach ($dates as $table => $columns) {
        DB::table($table)->update(array_map(fn (array $pair): ?string => $pair[0], $columns));
    }
}

/**
 * The dates the tables hold, in the shape of {@see parisDates()} (one row per table), read from the database as they are.
 *
 * @param  array<string, array<string, array{0: ?string, 1: ?string}>>  $dates  The tables and columns to read.
 * @return array<string, array<string, ?string>>
 */
function storedDates(array $dates): array
{
    $stored = [];

    foreach ($dates as $table => $columns) {
        $row = (array) DB::table($table)->sole();

        foreach (array_keys($columns) as $column) {
            $stored[$table][$column] = $row[$column] === null ? null : (string) $row[$column];
        }
    }

    return $stored;
}

/**
 * Either side of {@see parisDates()}: the dates stored in the local timezone (0) or in UTC (1).
 *
 * @param  array<string, array<string, array{0: ?string, 1: ?string}>>  $dates
 * @return array<string, array<string, ?string>>
 */
function datesOnSide(array $dates, int $side): array
{
    return array_map(fn (array $columns): array => array_map(fn (array $pair): ?string => $pair[$side], $columns), $dates);
}

describe('the migration that stores dates in UTC', function (): void {
    beforeEach(function (): void {
        $this->migration = require database_path('migrations/2026_10_20_000000_store_timestamps_in_utc.php');
        config(['app.display_timezone' => 'Europe/Paris']);
    });

    it('converts the dates from the display timezone to UTC, in summer and in winter', function (): void {
        seedDatedRows(parisDates());

        $this->migration->up();

        expect(storedDates(parisDates()))->toBe(datesOnSide(parisDates(), 1));
    });

    it('leaves the dates that are empty empty', function (): void {
        seedDatedRows(parisDates());
        DB::table('users')->update(['email_verified_at' => null]);

        $this->migration->up();

        expect(storedDate('users', 'email_verified_at'))->toBeNull()
            ->and(storedDate('personal_access_tokens', 'last_used_at'))->toBeNull()
            ->and(storedDate('users', 'created_at'))->toBe('2026-01-15 11:00:00');
    });

    it('deletes the pending sign-in and account deletion codes', function (): void {
        seedDatedRows(parisDates());

        $this->migration->up();

        expect(DB::table('login_codes')->count())->toBe(0)
            ->and(DB::table('account_deletion_codes')->count())->toBe(0);
    });

    it('does not touch anything else', function (): void {
        seedDatedRows(parisDates());
        $user = (array) DB::table('users')->sole();
        $workspace = (array) DB::table('workspaces')->sole();

        $this->migration->up();

        foreach (['name', 'email', 'locale', 'is_admin', 'current_workspace_id'] as $column) {
            expect(DB::table('users')->sole()->{$column})->toBe($user[$column]);
        }

        expect(DB::table('workspaces')->sole()->name)->toBe($workspace['name'])
            ->and(DB::table('settings')->sole()->value)->toBe('true');
    });

    it('does nothing when the display timezone is UTC', function (): void {
        config(['app.display_timezone' => 'UTC']);
        seedDatedRows(parisDates());

        $this->migration->up();

        expect(storedDates(parisDates()))->toBe(datesOnSide(parisDates(), 0))
            ->and(DB::table('login_codes')->count())->toBe(1)
            ->and(DB::table('account_deletion_codes')->count())->toBe(1);

        $this->migration->down();

        expect(storedDates(parisDates()))->toBe(datesOnSide(parisDates(), 0))
            ->and(DB::table('login_codes')->count())->toBe(1);
    });

    it('is undone by down(), which puts the original dates back and leaves the codes deleted', function (): void {
        seedDatedRows(parisDates());

        $this->migration->up();
        DB::table('login_codes')->insert(['email' => 'late@example.test', 'code_hash' => str_repeat('d', 64), 'expires_at' => now(), 'created_at' => now()]);
        $this->migration->down();

        expect(storedDates(parisDates()))->toBe(datesOnSide(parisDates(), 0))
            ->and(DB::table('login_codes')->count())->toBe(0)
            ->and(DB::table('account_deletion_codes')->count())->toBe(0);
    });

    it('converts back and forth without losing a second, in any timezone', function (string $zone): void {
        config(['app.display_timezone' => $zone]);
        seedDatedRows(parisDates());
        $original = storedDates(parisDates());

        $this->migration->up();

        expect(storedDates(parisDates()))->not->toBe($original);

        $this->migration->down();

        expect(storedDates(parisDates()))->toBe($original);
    })->with(['Europe/Paris', 'America/New_York', 'Asia/Kolkata', 'Pacific/Auckland']);

    it('converts to the right moment for a timezone behind UTC and for one with a half-hour offset', function (): void {
        seedDatedRows(parisDates());
        DB::table('users')->update(['created_at' => '2026-01-15 12:00:00', 'updated_at' => '2026-07-15 12:00:00']);

        config(['app.display_timezone' => 'America/New_York']);
        $this->migration->up();

        expect(storedDate('users', 'created_at'))->toBe('2026-01-15 17:00:00')
            ->and(storedDate('users', 'updated_at'))->toBe('2026-07-15 16:00:00');

        DB::table('users')->update(['created_at' => '2026-01-15 12:00:00', 'updated_at' => '2026-07-15 12:00:00']);

        config(['app.display_timezone' => 'Asia/Kolkata']);
        $this->migration->up();

        expect(storedDate('users', 'created_at'))->toBe('2026-01-15 06:30:00')
            ->and(storedDate('users', 'updated_at'))->toBe('2026-07-15 06:30:00');
    });

    it('converts more rows than one batch, whether the table is keyed by a number or by a name', function (): void {
        seedDatedRows(parisDates());
        $rows = range(1, 450);

        DB::table('settings')->insert(array_map(fn (int $n): array => [
            'key' => sprintf('setting_%03d', $n), 'value' => '1', 'created_at' => '2026-07-01 12:00:00', 'updated_at' => '2026-01-15 12:00:00',
        ], $rows));
        DB::table('workspaces')->insert(array_map(fn (int $n): array => [
            'ulid' => strtolower(sprintf('01hz%022d', $n)), 'name' => "Space {$n}", 'created_at' => '2026-07-01 12:00:00', 'updated_at' => '2026-01-15 12:00:00',
        ], $rows));

        $this->migration->up();

        expect(DB::table('settings')->where('created_at', '2026-07-01 10:00:00')->where('updated_at', '2026-01-15 11:00:00')->count())->toBe(450)
            ->and(DB::table('workspaces')->where('created_at', '2026-07-01 10:00:00')->where('updated_at', '2026-01-15 11:00:00')->count())->toBe(450)
            ->and(DB::table('settings')->count())->toBe(451)
            ->and(DB::table('workspaces')->count())->toBe(451);
    });

    it('leaves the database session in UTC, where it found it', function (): void {
        seedDatedRows(parisDates());

        $this->migration->up();

        expect(sqlSessionTimezone())->toBeIn(['+00:00', 'UTC']);

        $this->migration->down();

        expect(sqlSessionTimezone())->toBeIn(['+00:00', 'UTC']);
    });
});
