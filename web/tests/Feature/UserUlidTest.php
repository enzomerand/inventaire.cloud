<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\Ulid;

uses(RefreshDatabase::class);

/*
 * The identifier by which the other members of a workspace know an account: `users.ulid`. The migration that adds it is tested in
 * UserUlidMigrationTest.php.
 */

describe('the ulid of an account', function (): void {
    it('is given when the account is made, whatever makes it: 26 lowercase Crockford characters, the first of them 0 to 7', function (): void {
        $made = [
            User::factory()->create(),
            User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']),
            User::query()->forceCreate(['name' => 'Bob', 'email' => 'bob@example.test']),
            tap(new User(['name' => 'Cy', 'email' => 'cy@example.test']))->save(),
        ];

        foreach ($made as $user) {
            expect($user->fresh()->ulid)->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/')->and(Ulid::isValid($user->fresh()->ulid))->toBeTrue();
        }

        expect(array_unique(array_map(fn (User $user): string => $user->ulid, $made)))->toHaveCount(4);
    });

    it('is given even when the listeners of the model were removed', function (): void {
        User::flushEventListeners();

        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

        expect($user->fresh()->ulid)->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/');
    });

    it('is not something a request can set: it is not fillable, and a value given to the factory is kept', function (): void {
        expect((new User(['name' => 'Ada', 'ulid' => 'chosen']))->ulid)->toBeNull();

        $chosen = User::factory()->create(['ulid' => '7zzzzzzzzzzzzzzzzzzzzzzzzz']);

        expect($chosen->fresh()->ulid)->toBe('7zzzzzzzzzzzzzzzzzzzzzzzzz');
    });

    it('is unique: two accounts cannot share one', function (): void {
        $first = User::factory()->create();

        expect(fn () => User::factory()->create(['ulid' => $first->ulid]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('is drawn at random in full: unlike a real ULID it does not begin with the moment it was made', function (): void {
        $drawn = array_map(fn (): string => User::newUlid(), range(1, 300));
        $times = array_map(fn (string $ulid): string => substr($ulid, 0, 10), $drawn);
        $sorted = $times;
        sort($sorted);
        $firsts = array_values(array_unique(array_map(fn (string $ulid): string => $ulid[0], $drawn)));
        sort($firsts);

        expect(array_unique($drawn))->toHaveCount(300)
            // 48 bits of time would make every one of these begin alike, and in order.
            ->and(array_unique($times))->toHaveCount(300)
            ->and($times)->not->toBe($sorted)
            ->and($firsts)->toBe(['0', '1', '2', '3', '4', '5', '6', '7']);
    });

    it('stays out of what is serialized: the array of the account, the answer of GET /me and the list of the administrators', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $admin = User::factory()->admin()->create();

        $me = $this->actingAs($user)->getJson('/api/v1/me', frontend())->assertOk();

        expect($user->fresh()->toArray())->not->toHaveKey('ulid')
            ->and(json_encode($me->json()))->not->toContain($user->ulid);

        $this->flushSession();

        $users = $this->actingAs($admin)->getJson('/api/v1/admin/users', frontend())->assertOk();

        expect(json_encode($users->json()))->not->toContain($user->ulid);
    });
});
