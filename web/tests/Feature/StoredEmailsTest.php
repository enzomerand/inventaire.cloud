<?php

declare(strict_types=1);

use App\Database\StoredEmails;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Inserts accounts straight into the table, with the addresses exactly as given (what an older version stored).
 *
 * @param  list<string>  $addresses
 * @param  array<string, mixed>  $extra  Columns of the table as it is when the rows go in.
 */
function storeRawUsers(array $addresses, array $extra = []): void
{
    DB::table('users')->insert(array_map(fn (string $address): array => ['ulid' => User::newUlid(), 'name' => 'Someone', 'email' => $address, 'created_at' => now(), 'updated_at' => now()] + $extra, $addresses));
}

/**
 * The addresses in the table, sorted byte by byte (the engines do not agree on how to order capitals).
 *
 * @return list<string>
 */
function storedAddresses(): array
{
    $addresses = DB::table('users')->pluck('email')->all();

    sort($addresses, SORT_STRING);

    return $addresses;
}

describe('the stored addresses', function (): void {
    it('are brought to their canonical form, and the canonical ones are left alone', function (): void {
        storeRawUsers(['Ada@Example.COM', 'john@gmäil.com', 'grace@example.com']);

        StoredEmails::normalize();

        expect(storedAddresses())->toBe(['ada@example.com', 'grace@example.com', 'john@xn--gmil-moa.com']);
    });

    it('can be normalized again without any change', function (): void {
        storeRawUsers(['Ada@Example.COM']);
        StoredEmails::normalize();

        StoredEmails::normalize();

        expect(storedAddresses())->toBe(['ada@example.com']);
    });

    it('are not touched at all, and the clash is explained, when two accounts would end up with one address', function (): void {
        storeRawUsers(['Ada@Example.com', 'ada@example.com', 'Grace@Example.com']);

        expect(fn () => StoredEmails::normalize())->toThrow(RuntimeException::class, 'ada@example.com (ids ');

        expect(storedAddresses())->toBe(['Ada@Example.com', 'Grace@Example.com', 'ada@example.com']);
    });
});

describe('the passwordless migration', function (): void {
    beforeEach(function (): void {
        $this->migration = require database_path('migrations/2026_10_14_150000_make_users_passwordless.php');
    });

    it('lowercases and converts the addresses the accounts already have', function (): void {
        $this->migration->down();

        try {
            // The former schema still has its password column: nothing is written to it.
            storeRawUsers(['Ada@Example.COM', 'john@gmäil.com'], ['password' => '']);

            $this->migration->up();

            expect(storedAddresses())->toBe(['ada@example.com', 'john@xn--gmil-moa.com'])
                ->and(Schema::hasColumn('users', 'password'))->toBeFalse();
        } finally {
            // DDL ends the test's transaction on MySQL and MariaDB: do not leave the rows behind.
            DB::table('users')->delete();
        }
    });

    it('stops before changing anything when two accounts would share an address', function (): void {
        $this->migration->down();

        try {
            storeRawUsers(['Ada@Example.com', 'ada@example.com'], ['password' => '']);

            expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'these rows would share an address');

            // The schema is as it was, the addresses too, so that the clash can be settled and the migration run again.
            expect(Schema::hasColumn('users', 'password'))->toBeTrue()
                ->and(Schema::hasColumn('users', 'is_admin'))->toBeFalse()
                ->and(storedAddresses())->toBe(['Ada@Example.com', 'ada@example.com']);
        } finally {
            DB::table('users')->delete();
            $this->migration->up();
        }

        expect(Schema::hasColumn('users', 'password'))->toBeFalse()->and(User::query()->count())->toBe(0);
    });
});
