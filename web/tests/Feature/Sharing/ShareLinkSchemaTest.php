<?php

declare(strict_types=1);

use App\Installer\ApplicationTables;
use App\Models\Inventory;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/sharing.php';

uses(RefreshDatabase::class);

/**
 * Inserts a link the way a careless script would: straight into the table.
 *
 * @param  array<string, mixed>  $attributes
 */
function rawShareLink(int $inventoryId, array $attributes = []): void
{
    DB::table('share_links')->insert($attributes + [
        'ulid' => strtolower((string) Str::ulid()),
        'inventory_id' => $inventoryId,
        'token_hash' => hash('sha256', Str::random(20)),
        'token_encrypted' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('the table of share links', function (): void {
    it('has the columns the application relies on, and is one of the tables the installer knows', function (): void {
        expect(Schema::getColumnListing('share_links'))
            ->toEqualCanonicalizing(['id', 'ulid', 'inventory_id', 'created_by_id', 'label', 'include_files', 'token_hash', 'token_encrypted', 'expires_at', 'last_used_at', 'created_at', 'updated_at'])
            ->and(ApplicationTables::owns('share_links'))->toBeTrue();
    });

    it('is indexed for what reads it: the token by its hash, the links of an inventory, the author and the pruning by date', function (): void {
        expect(Schema::hasIndex('share_links', ['token_hash'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('share_links', ['ulid'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('share_links', ['inventory_id']))->toBeTrue()
            ->and(Schema::hasIndex('share_links', ['created_by_id']))->toBeTrue()
            ->and(Schema::hasIndex('share_links', ['expires_at']))->toBeTrue();
    });

    it('gives a link a ULID of its own, in lowercase, beside its numeric key, which the route uses', function (): void {
        $link = ShareLink::factory()->create();

        expect($link->id)->toBeInt()
            ->and($link->ulid)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
            ->and($link->getRouteKey())->toBe($link->ulid)
            ->and(ShareLink::factory()->create()->ulid)->not->toBe($link->ulid);
    });

    it('refuses two links with the same token, and keeps the hash at 64 characters', function (): void {
        $inventory = Inventory::factory()->create();
        rawShareLink($inventory->id, ['token_hash' => str_repeat('a', 64)]);

        expect(fn () => rawShareLink($inventory->id, ['token_hash' => str_repeat('a', 64)]))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => rawShareLink($inventory->id, ['token_hash' => str_repeat('b', 65)]))->toThrow(QueryException::class);
    });

    it('shows the files unless it is told otherwise, and has no expiry, no author and no use until they are set', function (): void {
        $inventory = Inventory::factory()->create();
        rawShareLink($inventory->id);

        $link = ShareLink::query()->sole();

        expect($link->include_files)->toBeTrue()
            ->and($link->expires_at)->toBeNull()
            ->and($link->last_used_at)->toBeNull()
            ->and($link->created_by_id)->toBeNull()
            ->and($link->label)->toBeNull();
    });

    it('holds a label of 80 characters and no more', function (): void {
        $inventory = Inventory::factory()->create();
        rawShareLink($inventory->id, ['label' => str_repeat('é', 80)]);

        expect(ShareLink::query()->sole()->label)->toBe(str_repeat('é', 80))
            ->and(fn () => rawShareLink($inventory->id, ['label' => str_repeat('é', 81)]))->toThrow(QueryException::class);
    });

    it('is deleted with its inventory, by the database', function (): void {
        $inventory = Inventory::factory()->create();
        $other = Inventory::factory()->create();
        rawShareLink($inventory->id);
        rawShareLink($inventory->id);
        rawShareLink($other->id);

        DB::table('inventories')->where('id', $inventory->id)->delete();

        expect(ShareLink::query()->pluck('inventory_id')->all())->toBe([$other->id]);
    });

    it('is deleted with the workspace, three levels down from it', function (): void {
        $inventory = Inventory::factory()->create();
        rawShareLink($inventory->id);

        DB::table('workspaces')->where('id', $inventory->workspace_id)->delete();

        expect(ShareLink::query()->count())->toBe(0);
    });

    it('forgets the person, not the link, when the account that made it is deleted', function (): void {
        $author = User::factory()->create();
        $link = ShareLink::factory()->create(['created_by_id' => $author->id]);

        $author->delete();

        expect($link->fresh()?->created_by_id)->toBeNull();
    });

    it('stores its dates in UTC', function (): void {
        $this->travelTo(now()->setDate(2026, 7, 1)->setTime(10, 0, 0, 0));
        $link = ShareLink::factory()->create(['expires_at' => now()->addDays(7)]);

        expect(storedDate('share_links', 'expires_at'))->toBe('2026-07-08 10:00:00')
            ->and(storedDate('share_links', 'created_at'))->toBe('2026-07-01 10:00:00')
            ->and($link->fresh()?->expires_at?->toIso8601String())->toBe('2026-07-08T10:00:00+00:00');
    });

    it('never gives its secrets to the array and JSON forms of the model', function (): void {
        $link = ShareLink::factory()->create();

        expect(array_keys($link->toArray()))->not->toContain('token_hash', 'token_encrypted')
            ->and($link->getAttribute('token_hash'))->toHaveLength(64);
    });
});
