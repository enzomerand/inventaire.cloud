<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../../Support/inventories.php';

/*
 * Tokens made at the same moment, in separate PHP processes against the real database (a single process cannot show it: see
 * tests/Feature/Admin/AdminConcurrencyTest.php). The number of tokens a person may have is counted under the lock of their row, so
 * that requests made together cannot each find a place for themselves and cross the limit together, on every engine. Each
 * scenario is repeated, as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

/**
 * Empties what these tests commit (the tokens first: nothing ties them to the accounts they belong to).
 */
function removeTokenConcurrencyRows(): void
{
    DB::table('personal_access_tokens')->delete();
    removeConcurrencyRows();
}

beforeEach(fn () => removeTokenConcurrencyRows());
afterEach(fn () => removeTokenConcurrencyRows());

describe('tokens made at the same moment', function (): void {
    it('give the last place to one of them', function (): void {
        foreach (range(1, 3) as $_) {
            $user = inventoryPerson('Ada');
            $user->createToken('first', ['read']);

            $outcomes = actTogether($this->sandbox->root, [
                ['create-token', $user->id, 'Second'],
                ['create-token', $user->id, 'Third'],
                ['create-token', $user->id, 'Fourth'],
                ['create-token', $user->id, 'Fifth'],
            ], ['MAX_API_TOKENS' => '2']);

            expect($outcomes)->toBe(['created', 'refused:token_limit_reached', 'refused:token_limit_reached', 'refused:token_limit_reached'])
                ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(2);

            removeTokenConcurrencyRows();
        }
    });

    it('count the tokens of each person apart', function (): void {
        $ada = inventoryPerson('Ada');
        $grace = inventoryPerson('Grace');

        $outcomes = actTogether($this->sandbox->root, [
            ['create-token', $ada->id, 'One'],
            ['create-token', $ada->id, 'Two'],
            ['create-token', $grace->id, 'One'],
            ['create-token', $grace->id, 'Two'],
        ], ['MAX_API_TOKENS' => '1']);

        expect($outcomes)->toBe(['created', 'created', 'refused:token_limit_reached', 'refused:token_limit_reached'])
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $ada->id)->count())->toBe(1)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $grace->id)->count())->toBe(1)
            ->and(User::query()->count())->toBe(2);
    });
});
