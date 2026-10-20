<?php

declare(strict_types=1);

use App\Models\ShareLink;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/sharing.php';

/*
 * Share links made and revoked at the same moment, in separate PHP processes against the real database (a single process cannot show it:
 * see tests/Feature/Admin/AdminConcurrencyTest.php). The number of links an inventory may have is counted under the lock of the inventory's
 * row, so that requests made together cannot each find a place for themselves and cross the limit together, on every engine. Each
 * scenario is repeated, as one lucky ordering proves nothing.
 */

uses(CommitsDatabaseChanges::class);

beforeEach(fn () => removeConcurrencyRows());
afterEach(fn () => removeConcurrencyRows());

describe('links made at the same moment', function (): void {
    it('give the last place of an inventory to one of them', function (): void {
        foreach (range(1, 3) as $_) {
            $case = shareLinkCase();
            shareLinkMake($case['inventory'], $case['owner']);

            $outcomes = actTogether($this->sandbox->root, [
                ['create-share-link', $case['owner']->id, $case['inventory']->id],
                ['create-share-link', $case['owner']->id, $case['inventory']->id],
                ['create-share-link', $case['team']['editor']->id, $case['inventory']->id],
                ['create-share-link', $case['team']['viewer']->id, $case['inventory']->id],
            ], ['INVENTORIES_MAX_SHARE_LINKS' => '2']);

            expect($outcomes)->toBe(['created', 'refused:share_link_limit_reached', 'refused:share_link_limit_reached', 'refused:share_link_limit_reached'])
                ->and(ShareLink::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(2);

            removeConcurrencyRows();
        }
    });

    it('count the links of each inventory apart', function (): void {
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Other');

        $outcomes = actTogether($this->sandbox->root, [
            ['create-share-link', $case['owner']->id, $case['inventory']->id],
            ['create-share-link', $case['owner']->id, $case['inventory']->id],
            ['create-share-link', $case['owner']->id, $other->id],
            ['create-share-link', $case['owner']->id, $other->id],
        ], ['INVENTORIES_MAX_SHARE_LINKS' => '1']);

        expect($outcomes)->toBe(['created', 'created', 'refused:share_link_limit_reached', 'refused:share_link_limit_reached'])
            ->and(ShareLink::query()->where('inventory_id', $case['inventory']->id)->count())->toBe(1)
            ->and(ShareLink::query()->where('inventory_id', $other->id)->count())->toBe(1);
    });
});

describe('a link revoked while others are made', function (): void {
    it('is revoked once, and leaves room for one more only', function (): void {
        foreach (range(1, 3) as $_) {
            $case = shareLinkCase();
            ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);

            $outcomes = actTogether($this->sandbox->root, [
                ['revoke-share-link', $case['inventory']->id, $link->id],
                ['revoke-share-link', $case['inventory']->id, $link->id],
            ]);

            expect($outcomes)->toBe(['refused:gone', 'revoked'])
                ->and(DB::table('share_links')->where('inventory_id', $case['inventory']->id)->count())->toBe(0);

            removeConcurrencyRows();
        }
    });

    it('does not let a link slip in past the limit while one goes', function (): void {
        $case = shareLinkCase();
        ['link' => $link] = shareLinkMake($case['inventory'], $case['owner']);

        $outcomes = actTogether($this->sandbox->root, [
            ['revoke-share-link', $case['inventory']->id, $link->id],
            ['create-share-link', $case['owner']->id, $case['inventory']->id],
            ['create-share-link', $case['owner']->id, $case['inventory']->id],
        ], ['INVENTORIES_MAX_SHARE_LINKS' => '1']);

        // Whichever order they run in, the inventory ends up with at most one link, and the other creations were refused for it.
        expect(ShareLink::query()->where('inventory_id', $case['inventory']->id)->count())->toBeLessThanOrEqual(1)
            ->and($outcomes)->toContain('revoked')
            ->and(array_diff($outcomes, ['revoked', 'created', 'refused:share_link_limit_reached']))->toBe([]);
    });
});
