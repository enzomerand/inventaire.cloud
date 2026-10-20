<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommitsDatabaseChanges;

/*
 * The migration that gives every account its `ulid`, run on whichever engine the suite runs on (PostgreSQL, MySQL, MariaDB).
 *
 * It changes the table, which MySQL and MariaDB cannot roll back: the tests do not run in a transaction, put the table back as it was
 * (with the column) when they are done, and empty what they wrote.
 */

uses(CommitsDatabaseChanges::class);

/**
 * The migration under test.
 */
function userUlidMigration(): Migration
{
    return require database_path('migrations/2026_11_14_000000_add_ulid_to_users_table.php');
}

beforeEach(function (): void {
    removeConcurrencyRows();
});

afterEach(function (): void {
    removeConcurrencyRows();

    // Whatever a test left half done, the table is what the rest of the suite expects: the column is there, unique and mandatory.
    if (! Schema::hasColumn('users', 'ulid')) {
        userUlidMigration()->up();
    }
});

/**
 * Rows of the table of accounts as they were before the migration, with a made-up address each.
 *
 * @param  array<string, mixed>  $extra
 */
function accountsWithoutUlid(int $count, array $extra = []): void
{
    $now = now();

    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $n): array => [
            'name' => "User {$n}", 'email' => "user{$n}@example.test", 'remember_token' => 'token', 'created_at' => $now, 'updated_at' => $now,
        ] + $extra, $chunk));
    }
}

describe('the migration that adds users.ulid', function (): void {
    it('gives every existing account a ulid of its own, in several rounds, and then makes the column unique and mandatory', function (): void {
        userUlidMigration()->down();
        accountsWithoutUlid(1100);

        userUlidMigration()->up();

        $ulids = DB::table('users')->pluck('ulid')->all();

        expect($ulids)->toHaveCount(1100)
            ->and(array_unique($ulids))->toHaveCount(1100)
            ->and(array_filter($ulids, fn ($ulid): bool => preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', (string) $ulid) !== 1))->toBe([])
            ->and(Schema::hasColumn('users', 'ulid'))->toBeTrue()
            ->and(Schema::hasIndex('users', ['ulid'], 'unique'))->toBeTrue();

        // Unique: the same value twice is refused.
        expect(fn () => DB::table('users')->where('email', 'user2@example.test')->update(['ulid' => $ulids[0]]))->toThrow(UniqueConstraintViolationException::class);

        // Mandatory: an account cannot be inserted without one.
        expect(fn () => DB::table('users')->insert(['name' => 'Late', 'email' => 'late@example.test', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    });

    it('keeps the ulid an account has, and finishes a backfill that stopped half way', function (): void {
        userUlidMigration()->down();
        Schema::table('users', function ($table): void {
            $table->ulid('ulid')->nullable();
        });
        accountsWithoutUlid(3);
        DB::table('users')->where('email', 'user1@example.test')->update(['ulid' => '7abcdefghjkmnpqrstvwxyz012']);
        DB::table('users')->where('email', 'user2@example.test')->update(['ulid' => '0abcdefghjkmnpqrstvwxyz012']);

        userUlidMigration()->up();

        $ulids = DB::table('users')->orderBy('id')->pluck('ulid', 'email')->all();

        expect($ulids['user1@example.test'])->toBe('7abcdefghjkmnpqrstvwxyz012')
            ->and($ulids['user2@example.test'])->toBe('0abcdefghjkmnpqrstvwxyz012')
            ->and($ulids['user3@example.test'])->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/')
            ->and(array_unique($ulids))->toHaveCount(3);
    });

    it('works on an empty table', function (): void {
        userUlidMigration()->down();

        userUlidMigration()->up();

        expect(DB::table('users')->count())->toBe(0)->and(Schema::hasIndex('users', ['ulid'], 'unique'))->toBeTrue();
    });

    it('leaves the rest of the account as it was', function (): void {
        userUlidMigration()->down();
        accountsWithoutUlid(2, ['locale' => 'fr', 'is_admin' => true]);

        userUlidMigration()->up();

        $user = User::query()->where('email', 'user1@example.test')->sole();

        expect($user->name)->toBe('User 1')->and($user->locale)->toBe('fr')->and($user->is_admin)->toBeTrue()->and($user->remember_token)->toBe('token');
    });

    it('is undone by taking the column away, and can be done again', function (): void {
        User::factory()->create(['ulid' => Str::lower('7ZZZZZZZZZZZZZZZZZZZZZZZZZ')]);

        userUlidMigration()->down();

        expect(Schema::hasColumn('users', 'ulid'))->toBeFalse()->and(DB::table('users')->count())->toBe(1);

        userUlidMigration()->up();

        expect(Schema::hasColumn('users', 'ulid'))->toBeTrue()->and(DB::table('users')->count())->toBe(1);
    });
});
