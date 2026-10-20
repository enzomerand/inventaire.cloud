<?php

declare(strict_types=1);

use App\Auth\AccountExport;
use App\Models\ShareLink;
use App\Models\User;
use App\Workspaces\Members;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/sharing.php';

uses(RefreshDatabase::class);

describe('the share links in the export of the account', function (): void {
    it('are an empty list for an account that made none, in a document that keeps its version', function (): void {
        $export = $this->actingAs(User::factory()->create())->getJson('/api/v1/me/export', frontend())->assertOk()->json();

        expect($export)->toHaveKey('share_links')
            ->and($export['share_links'])->toBe([])
            ->and($export['version'])->toBe(AccountExport::VERSION);
    });

    it('list those the account made, newest first: the inventory, the label, the files, and the dates', function (): void {
        $this->travelTo(now()->startOfSecond());
        $case = shareLinkCase();
        $other = inventoryIn($case['team']['workspace'], 'Office');
        ['link' => $first] = shareLinkMake($case['inventory'], $case['owner'], 'Insurer', false, 30);
        $this->travelTo(now()->addMinute());
        ['link' => $second] = shareLinkMake($other, $case['owner'], null, true, null);
        ShareLink::query()->whereKey($first->id)->update(['last_used_at' => now()]);

        $links = app(AccountExport::class)->for($case['owner'])['share_links'];

        expect($links)->toBe([
            [
                'inventory' => ['id' => $other->ulid, 'name' => 'Office'],
                'label' => null,
                'include_files' => true,
                'created_at' => now()->toIso8601String(),
                'expires_at' => null,
                'last_used_at' => null,
            ],
            [
                'inventory' => ['id' => $case['inventory']->ulid, 'name' => 'Tools'],
                'label' => 'Insurer',
                'include_files' => false,
                'created_at' => now()->subMinute()->toIso8601String(),
                'expires_at' => now()->subMinute()->addDays(30)->toIso8601String(),
                'last_used_at' => now()->toIso8601String(),
            ],
        ])->and($second->ulid)->not->toBe($first->ulid);
    });

    it('hold neither the token nor what the database keeps of it, nor the identifier of a link', function (): void {
        $case = shareLinkCase();
        ['link' => $link, 'token' => $token] = shareLinkMake($case['inventory'], $case['owner'], 'Insurer');

        $body = (string) $this->actingAs($case['owner'])->getJson('/api/v1/me/export', frontend())->assertOk()->getContent();

        expect($body)->not->toContain($token)
            ->not->toContain($link->token_hash)
            ->not->toContain((string) $link->token_encrypted)
            ->not->toContain('token_hash')->not->toContain('token_encrypted')
            ->not->toContain($link->ulid)
            ->toContain('Insurer');
    });

    it('include the links that expired and were not deleted yet', function (): void {
        $case = shareLinkCase();
        ShareLink::factory()->for($case['inventory'])->expired()->create(['created_by_id' => $case['owner']->id, 'label' => 'Old']);

        $links = app(AccountExport::class)->for($case['owner'])['share_links'];

        expect($links)->toHaveCount(1)->and($links[0]['label'])->toBe('Old');
    });

    it('leave out the links of somebody else, and those whose author is gone', function (): void {
        $case = shareLinkCase();
        $second = User::factory()->withPersonalWorkspace()->create();
        $case['team']['workspace']->members()->attach($second->id, ['role' => 'owner']);
        shareLinkMake($case['inventory'], $second, 'Theirs');
        ShareLink::factory()->for($case['inventory'])->create(['created_by_id' => null]);
        shareLinkMake($case['inventory'], $case['owner'], 'Mine');

        $links = app(AccountExport::class)->for($case['owner'])['share_links'];

        expect(array_column($links, 'label'))->toBe(['Mine'])
            ->and(array_column(app(AccountExport::class)->for($second)['share_links'], 'label'))->toBe(['Theirs']);
    });

    it('list only the links of the inventories of the workspaces the account still owns: the name of an inventory is not the business of somebody who lost the workspace', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $successor = User::factory()->withPersonalWorkspace()->create();
        $kept = app(Workspaces::class)->create($owner, 'Kept');
        $handedOver = app(Workspaces::class)->create($owner, 'Handed over');
        $handedOver->members()->attach($successor->id, ['role' => 'editor']);
        shareLinkMake(inventoryIn($kept, 'Kept inventory'), $owner, 'Kept link');
        shareLinkMake(inventoryIn($handedOver, 'Handed inventory'), $owner, 'Handed link');
        app(Members::class)->transferOwnership($owner, $handedOver, $successor);

        $links = app(AccountExport::class)->for($owner)['share_links'];

        expect(array_column($links, 'label'))->toBe(['Kept link'])
            ->and($links[0]['inventory']['name'])->toBe('Kept inventory')
            ->and(app(AccountExport::class)->for($successor)['share_links'])->toBe([]);
    });
});
