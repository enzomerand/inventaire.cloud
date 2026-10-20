<?php

declare(strict_types=1);

use App\Database\ExactEmailColumns;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** The address of the victim, and the one of an attacker who owns the lookalike domain gmäil.com. */
const VICTIM_ADDRESS = 'john.doe@gmail.com';
const LOOKALIKE_ADDRESS = 'john.doe@gmäil.com';
const LOOKALIKE_STORED = 'john.doe@xn--gmil-moa.com';

/**
 * Whether the connection is one of the engines whose default collation ignores accents.
 */
function ignoresAccentsByDefault(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

/**
 * The collation of a column, or null where the engine has none to speak of (PostgreSQL compares bytes).
 */
function collationOf(string $table, string $column): ?string
{
    if (! ignoresAccentsByDefault()) {
        return null;
    }

    $row = DB::selectOne('select collation_name as collation from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?', [$table, $column]);

    return $row->collation;
}

/**
 * Inserts rows the way a careless script would: straight into the table, with no normalization.
 *
 * @param  list<string>  $addresses
 */
function insertUsersRaw(array $addresses): void
{
    DB::table('users')->insert(array_map(fn (string $address): array => ['ulid' => User::newUlid(), 'name' => $address, 'email' => $address, 'created_at' => now(), 'updated_at' => now()], $addresses));
}

describe('an address on a lookalike domain', function (): void {
    it('is stored with its domain in ASCII, and gets its own code', function (): void {
        $victim = User::factory()->create(['email' => VICTIM_ADDRESS]);

        $victimCode = receiveCode($this, VICTIM_ADDRESS);
        $victimHash = LoginCode::query()->findOrFail(VICTIM_ADDRESS)->code_hash;

        askForCode($this, LOOKALIKE_ADDRESS)->assertOk()->assertJsonPath('data.email', LOOKALIKE_STORED);

        expect(sentMails())->toHaveCount(2)
            ->and(sentMails()[1]->getTo()[0]->getAddress())->toBe(LOOKALIKE_STORED)
            ->and(LoginCode::query()->orderBy('email')->pluck('email')->all())->toBe([VICTIM_ADDRESS, LOOKALIKE_STORED])
            // The victim's pending code was not touched, and still opens their account.
            ->and(LoginCode::query()->findOrFail(VICTIM_ADDRESS)->code_hash)->toBe($victimHash);

        typeCode($this, VICTIM_ADDRESS, $victimCode)->assertOk()->assertJsonPath('data.id', $victim->id);
    });

    it('cannot sign in as the victim with the code of its own address', function (): void {
        $victim = User::factory()->create(['email' => VICTIM_ADDRESS]);
        $victimCode = receiveCode($this, VICTIM_ADDRESS);

        $attackerCode = receiveCode($this, LOOKALIKE_ADDRESS);

        // The attacker's code opens the attacker's own address (a new account), never the victim's.
        if ($attackerCode !== $victimCode) {
            typeCode($this, VICTIM_ADDRESS, $attackerCode)->assertUnprocessable()->assertJsonPath('code', 'login_code_wrong');
            $this->assertGuest('web');
        }

        $response = typeCode($this, LOOKALIKE_ADDRESS, $attackerCode, ['name' => 'Mallory'])->assertCreated();

        expect($response->json('data.id'))->not->toBe($victim->id)
            ->and($response->json('data.email'))->toBe(LOOKALIKE_STORED)
            ->and(User::query()->orderBy('id')->pluck('email')->all())->toBe([VICTIM_ADDRESS, LOOKALIKE_STORED]);
    });

    it('does not find the account of the victim, even when it is looked up as typed', function (): void {
        User::factory()->create(['name' => 'Victim', 'email' => VICTIM_ADDRESS]);
        $code = receiveCode($this, LOOKALIKE_ADDRESS);

        // An account of its own is asked for: the victim is not signed in under another name.
        typeCode($this, LOOKALIKE_ADDRESS, $code)->assertUnprocessable()->assertJsonPath('code', 'name_required');

        $this->assertGuest('web');
    });
});

describe('the database', function (): void {
    it('tells the lookalike apart from the real address, whatever the application does', function (): void {
        // Straight into the tables: this is the second layer, which holds when the first one is bypassed.
        insertUsersRaw([VICTIM_ADDRESS, LOOKALIKE_ADDRESS, 'John.Doe@gmail.com']);

        foreach ([VICTIM_ADDRESS, LOOKALIKE_ADDRESS, 'John.Doe@gmail.com'] as $address) {
            expect(User::query()->where('email', $address)->pluck('email')->all())->toBe([$address]);
        }

        $row = fn (string $email): array => ['email' => $email, 'code_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'created_at' => now()];
        DB::table('login_codes')->insert([$row(VICTIM_ADDRESS), $row(LOOKALIKE_ADDRESS)]);

        expect(LoginCode::query()->whereKey(LOOKALIKE_ADDRESS)->count())->toBe(1)
            ->and(LoginCode::query()->whereKey(VICTIM_ADDRESS)->count())->toBe(1)
            ->and(LoginCode::query()->count())->toBe(2);
    });

    it('does not let an upsert for the lookalike overwrite the pending code of the real address', function (): void {
        $row = fn (string $email, string $hash): array => ['email' => $email, 'code_hash' => str_repeat($hash, 64), 'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'created_at' => now()];

        LoginCode::query()->upsert([$row(VICTIM_ADDRESS, 'a')], ['email'], ['code_hash', 'expires_at', 'attempts', 'created_at']);
        LoginCode::query()->upsert([$row(LOOKALIKE_ADDRESS, 'b')], ['email'], ['code_hash', 'expires_at', 'attempts', 'created_at']);

        expect(LoginCode::query()->findOrFail(VICTIM_ADDRESS)->code_hash)->toBe(str_repeat('a', 64))
            ->and(LoginCode::query()->count())->toBe(2);
    });

    it('compares email columns exactly: a binary collation on MySQL and MariaDB, bytes on PostgreSQL', function (): void {
        $expected = ignoresAccentsByDefault() ? 'utf8mb4_bin' : null;

        expect(collationOf('users', 'email'))->toBe($expected)
            ->and(collationOf('login_codes', 'email'))->toBe($expected);
    });

    it('would confuse the two without that collation: the migration is what prevents it', function (): void {
        if (! ignoresAccentsByDefault()) {
            // PostgreSQL compares bytes: the lookalike is a different address from the start.
            insertUsersRaw([VICTIM_ADDRESS, LOOKALIKE_ADDRESS]);
            expect(User::query()->count())->toBe(2);

            return;
        }

        $migration = require database_path('migrations/2026_10_14_150200_compare_emails_exactly.php');

        // Back to the default collation of the connection, as it was before the migration.
        $migration->down();

        try {
            expect(collationOf('users', 'email'))->not->toBe(ExactEmailColumns::COLLATION)
                ->and(fn () => insertUsersRaw([VICTIM_ADDRESS, LOOKALIKE_ADDRESS]))->toThrow(UniqueConstraintViolationException::class);
        } finally {
            $migration->up();
        }

        // Nothing is written after the schema changes: DDL ends the test's transaction on MySQL and MariaDB.
        expect(collationOf('users', 'email'))->toBe(ExactEmailColumns::COLLATION)
            ->and(collationOf('login_codes', 'email'))->toBe(ExactEmailColumns::COLLATION);
    });
});
