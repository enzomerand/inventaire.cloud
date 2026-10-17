<?php

declare(strict_types=1);

use App\Auth\ApiTokens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The tokens of an account go when the account does. They are found first and deleted by key: a DELETE on the two columns that say whose
 * a token is scans a range of the index and, at the default isolation level of MySQL and MariaDB, keeps it locked (and the gap after it)
 * for the whole of the deletion of the account, which stops another person's token from being made (see TokenConcurrencyTest for that,
 * which only shows on those engines; the shape of the statement is checked here on all of them).
 */

/**
 * The statements that delete from the table of tokens, with their bindings, made by a callback.
 *
 * @return list<array{query: string}>
 */
function tokenDeletionsDuring(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    DB::disableQueryLog();

    return array_values(array_map(
        fn (array $entry): array => ['query' => (string) $entry['query']],
        array_filter(DB::getQueryLog(), fn (array $entry): bool => preg_match('/^delete from ["`]?personal_access_tokens["`]?/i', (string) $entry['query']) === 1),
    ));
}

describe('deleting an account', function (): void {
    it('revokes its tokens and only its tokens', function (): void {
        $ada = inventoryPerson('Ada');
        $grace = inventoryPerson('Grace');
        $ada->createToken('one', ['read']);
        $ada->createToken('two', ['write']);
        $graceToken = $grace->createToken('hers', ['read'])->accessToken;

        $ada->delete();

        expect(DB::table('personal_access_tokens')->pluck('id')->all())->toBe([$graceToken->id])
            ->and(User::query()->whereKey($ada->id)->exists())->toBeFalse();
    });

    it('deletes the tokens by key, not with a scan of the index on the owner of a token', function (): void {
        $ada = inventoryPerson('Ada');
        $first = $ada->createToken('one', ['read'])->accessToken;
        $second = $ada->createToken('two', ['write'])->accessToken;

        $deletions = tokenDeletionsDuring(fn () => $ada->delete());

        // The keys are written into the statement (the framework does that for a list of integers), not bound.
        preg_match('/ in \(([0-9, ]+)\)/', $deletions[0]['query'] ?? '', $keys);

        expect($deletions)->toHaveCount(1)
            ->and($deletions[0]['query'])->not->toContain('tokenable')
            ->and(array_map(intval(...), explode(', ', $keys[1] ?? '')))->toEqualCanonicalizing([$first->id, $second->id]);
    });

    it('deletes nothing from the table of tokens for an account that has none', function (): void {
        $ada = inventoryPerson('Ada');

        expect(tokenDeletionsDuring(fn () => $ada->delete()))->toBe([]);
    });
});

describe('revoking every token of a person', function (): void {
    it('says how many there were, and leaves the tokens of others', function (): void {
        $ada = inventoryPerson('Ada');
        $grace = inventoryPerson('Grace');
        $ada->createToken('one', ['read']);
        $ada->createToken('two', ['read']);
        $grace->createToken('hers', ['read']);

        expect(app(ApiTokens::class)->revokeAll($ada))->toBe(2)
            ->and(app(ApiTokens::class)->revokeAll($ada))->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $grace->id)->count())->toBe(1);
    });
});
