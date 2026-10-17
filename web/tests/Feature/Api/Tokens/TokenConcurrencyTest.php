<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsDatabaseChanges;
use Tests\Support\ConcurrentChildren;

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

describe('the tokens of an account that is being deleted', function (): void {
    it('do not make the token of another person wait while the deletion is under way', function (): void {
        $ada = inventoryPerson('Ada');
        $ada->createToken('one', ['read']);
        $ada->createToken('two', ['write']);
        // The next account: its token goes right after hers in the index on the owner of a token.
        $grace = inventoryPerson('Grace');

        // One process revokes the tokens of an account the way its deletion does and keeps its transaction open, as that one is long
        // (workspaces, files); another inserts a token for the next account meanwhile. A lock on the range of the index that the
        // revocation scanned, and on the gap after it (MySQL and MariaDB), would make that insert wait for the first to finish, which
        // it never does: it gives up when its lock wait times out. On PostgreSQL nothing is locked beyond the rows either way.
        $signals = [$this->sandbox->root.'/go.locked', $this->sandbox->root.'/go.released'];
        array_map(fn (string $signal): bool => @unlink($signal), $signals);

        try {
            $answers = ConcurrentChildren::run($this->sandbox->root, base_path('tests/Support/concurrent-action.php'), [['hold-token-revocation', $ada->id], ['insert-token', $grace->id]]);
        } finally {
            array_map(fn (string $signal): bool => @unlink($signal), $signals);
        }

        expect($answers[0]['result'])->toBe('held')
            ->and($answers[1]['result'])->toBe('inserted')
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $ada->id)->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $grace->id)->count())->toBe(1);
    });
});
