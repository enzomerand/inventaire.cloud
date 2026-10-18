<?php

declare(strict_types=1);

use App\Auth\AccountExport;
use App\Inventories\Items;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/attachments.php';

uses(RefreshDatabase::class);

/*
 * The inventories in the export of an account: what describes them and their fields, and how many items they hold.
 * Never the items themselves: see the docblock of AccountExport.
 */

describe('the inventories in the export of the account', function (): void {
    it('are listed in each workspace, with their fields and the number of their items', function (): void {
        $team = inventoryTeam();
        $tools = inventoryIn($team['workspace'], 'Tools');
        $tools->forceFill(['description' => "Everything\nwe lend."])->save();
        $weight = fieldIn($tools, 'number', 'Weight', ['decimals' => 1, 'unit' => 'kg']);
        $name = fieldIn($tools, 'text', 'Name', required: true);
        $date = fieldIn($tools, 'date', 'Bought on');
        itemIn($tools, [$name->ulid => 'Hammer', $weight->ulid => '2.5']);
        itemIn($tools, [$name->ulid => 'Saw']);
        $empty = inventoryIn($team['workspace'], 'Empty');

        $workspaces = inventoryCall($this, $team['owner'], 'GET', '/api/v1/me/export')->assertOk()->json('workspaces');
        $shop = collect($workspaces)->firstWhere('id', $team['workspace']->ulid);

        // MySQL keeps the keys of a JSON object in an order of its own: the options are compared as a set.
        expect($shop['inventories'])->toEqual([
            [
                'id' => $tools->ulid,
                'name' => 'Tools',
                'description' => "Everything\nwe lend.",
                'created_at' => $tools->created_at->toIso8601String(),
                'updated_at' => $tools->fresh()->updated_at->toIso8601String(),
                'fields' => [
                    ['id' => $weight->ulid, 'name' => 'Weight', 'type' => 'number', 'options' => ['decimals' => 1, 'unit' => 'kg'], 'required' => false, 'position' => 0],
                    ['id' => $name->ulid, 'name' => 'Name', 'type' => 'text', 'options' => ['max_length' => 255], 'required' => true, 'position' => 1],
                    ['id' => $date->ulid, 'name' => 'Bought on', 'type' => 'date', 'options' => [], 'required' => false, 'position' => 2],
                ],
                'items_count' => 2,
            ],
            [
                'id' => $empty->ulid,
                'name' => 'Empty',
                'description' => null,
                'created_at' => $empty->created_at->toIso8601String(),
                'updated_at' => $empty->updated_at->toIso8601String(),
                'fields' => [],
                'items_count' => 0,
            ],
        ]);
    });

    it('never hold the items: not their values, not their identifiers, not their dates', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace'], 'Tools');
        $name = fieldIn($inventory, 'text', 'Name');
        $items = [itemIn($inventory, [$name->ulid => 'A very particular hammer']), itemIn($inventory, [$name->ulid => 'Second item'])];

        $body = (string) inventoryCall($this, $team['owner'], 'GET', '/api/v1/me/export')->assertOk()->getContent();

        expect($body)->not->toContain('A very particular hammer')->not->toContain('Second item')
            ->and($body)->not->toContain($items[0]->ulid)->not->toContain($items[1]->ulid)
            ->and($body)->toContain('"items_count": 2');
    });

    it('write the options of a field as an object, also when the type has none', function (): void {
        $team = inventoryTeam();
        fieldIn(inventoryIn($team['workspace']), 'boolean', 'In stock');

        $body = (string) inventoryCall($this, $team['owner'], 'GET', '/api/v1/me/export')->assertOk()->getContent();

        expect($body)->toContain('"options": {}')->not->toContain('"options": []');
    });

    it('are those of every workspace the person belongs to, whatever their role there, and no others', function (): void {
        $team = inventoryTeam();
        $personal = $team['viewer']->workspaces()->orderBy('workspace_user.id')->firstOrFail();
        $mine = inventoryIn($personal, 'Mine');
        $shared = inventoryIn($team['workspace'], 'Shared');
        fieldIn($shared, 'text', 'Name');
        $strangers = inventoryIn($team['stranger']->workspaces()->sole(), 'Hers');
        fieldIn($strangers, 'text', 'Her secret field');

        $export = inventoryCall($this, $team['viewer'], 'GET', '/api/v1/me/export')->assertOk()->json('workspaces');
        $names = collect($export)->mapWithKeys(fn (array $workspace): array => [$workspace['id'] => array_column($workspace['inventories'], 'name')])->all();

        expect($names)->toBe([$personal->ulid => ['Mine'], $team['workspace']->ulid => ['Shared']]);

        $body = (string) json_encode($export);
        expect($body)->not->toContain('Hers')->not->toContain('Her secret field')->not->toContain($strangers->ulid)->not->toContain($mine->ulid.'x');
    });

    it('come oldest first, and the fields in the order they are shown', function (): void {
        $team = inventoryTeam();
        $second = inventoryIn($team['workspace'], 'Zebras');
        $first = inventoryIn($team['workspace'], 'Aardvarks');
        $one = fieldIn($first, 'text', 'One');
        $two = fieldIn($first, 'text', 'Two');
        inventoryCall($this, $team['owner'], 'PUT', "/api/v1/inventories/{$first->ulid}/fields/order", ['fields' => [$two->ulid, $one->ulid]])->assertOk();

        $shop = collect(inventoryCall($this, $team['owner'], 'GET', '/api/v1/me/export')->assertOk()->json('workspaces'))->firstWhere('id', $team['workspace']->ulid);

        expect(array_column($shop['inventories'], 'id'))->toBe([$second->ulid, $first->ulid])
            ->and(array_column($shop['inventories'][1]['fields'], 'name'))->toBe(['Two', 'One']);
    });

    it('cost the same number of queries however many workspaces and inventories there are', function (): void {
        $person = inventoryPerson('Ada');
        $count = fn (): int => count(statementsOf(fn () => app(AccountExport::class)->for($person)));
        $first = inventoryIn($person->workspaces()->sole());
        fieldIn($first, 'text', 'Name');

        $before = $count();

        foreach (range(1, 4) as $number) {
            $workspace = app(Workspaces::class)->create($person, "Shop {$number}");

            foreach (range(1, 3) as $inventory) {
                $made = inventoryIn($workspace, "Inventory {$number}.{$inventory}");
                itemIn($made);
                fieldIn($made, 'text', 'Name');
            }
        }

        expect($count())->toBe($before);
    });

    it('are in the document of the version it already has: only the content of the inventories list is new', function (): void {
        $team = inventoryTeam();
        inventoryIn($team['workspace']);

        $export = inventoryCall($this, $team['owner'], 'GET', '/api/v1/me/export')->assertOk()->json();

        expect($export['version'])->toBe(AccountExport::VERSION)
            ->and(array_keys($export))->toBe(['format', 'version', 'exported_at', 'user', 'workspaces', 'sessions', 'tokens'])
            ->and(array_keys($export['workspaces'][0]))->toBe(['id', 'name', 'role', 'is_current', 'created_at', 'updated_at', 'revisions_written', 'files_uploaded', 'official_versions_created', 'inventories']);
    });
});

describe('what the account wrote, in the export of the account', function (): void {
    it('counts, in each workspace, the revisions the person wrote and the files they uploaded with their weight, and nobody else\'s', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $personal = $team['editor']->workspaces()->orderBy('workspace_user.id')->firstOrFail();
        $shop = inventoryIn($team['workspace'], 'Tools');
        $mine = inventoryIn($personal, 'Mine');
        $name = fieldIn($shop, 'text', 'Name');
        $hammer = itemIn($shop, [$name->ulid => 'Hammer'], $team['editor']);
        app(Items::class)->update($shop, $hammer, [$name->ulid => 'Sledgehammer'], $team['editor']);
        app(Items::class)->update($shop, $hammer, [$name->ulid => 'Saw'], $team['owner']);
        itemIn($mine, [], $team['editor']);
        $first = attachmentTo($shop, $hammer, attachmentPdf('a.pdf'), $team['editor']);
        $second = attachmentTo($shop, $hammer, attachmentUploadedAs('b.txt', str_repeat('b', 100)), $team['editor']);
        attachmentTo($shop, $hammer, attachmentPdf('theirs.pdf'), $team['owner']);

        $workspaces = collect(inventoryCall($this, $team['editor'], 'GET', '/api/v1/me/export')->assertOk()->json('workspaces'))->keyBy('id');

        // In the shop: the creation and one change (the owner's change is not hers), then two of the three files, and the file of the owner stays out.
        // In the personal workspace: the creation of an item, no file.
        expect($workspaces[$team['workspace']->ulid]['revisions_written'])->toBe(2 + 2)
            ->and($workspaces[$team['workspace']->ulid]['files_uploaded'])->toBe(['count' => 2, 'bytes' => $first->size + $second->size])
            ->and($workspaces[$personal->ulid]['revisions_written'])->toBe(1)
            ->and($workspaces[$personal->ulid]['files_uploaded'])->toBe(['count' => 0, 'bytes' => 0]);
    });

    it('counts nothing for a person who wrote nothing, and nothing in a workspace they are not in', function (): void {
        attachmentDisk();
        $team = inventoryTeam();
        $shop = inventoryIn($team['workspace'], 'Tools');
        attachmentTo($shop, itemIn($shop, [], $team['owner']), attachmentPdf(), $team['owner']);
        $elsewhere = inventoryIn($team['stranger']->workspaces()->sole(), 'Hers');
        attachmentTo($elsewhere, itemIn($elsewhere, [], $team['stranger']), attachmentPdf(), $team['stranger']);

        $workspaces = inventoryCall($this, $team['viewer'], 'GET', '/api/v1/me/export')->assertOk()->json('workspaces');

        expect(array_column($workspaces, 'revisions_written'))->toBe([0, 0])
            ->and(array_column($workspaces, 'files_uploaded'))->toBe([['count' => 0, 'bytes' => 0], ['count' => 0, 'bytes' => 0]]);
    });

    it('cost the same number of queries however many workspaces there are', function (): void {
        $person = inventoryPerson('Ada');
        $count = fn (): int => count(statementsOf(fn () => app(AccountExport::class)->for($person)));
        itemIn(inventoryIn($person->workspaces()->sole()), [], $person);
        $before = $count();

        foreach (range(1, 4) as $number) {
            itemIn(inventoryIn(app(Workspaces::class)->create($person, "Shop {$number}")), [], $person);
        }

        expect($count())->toBe($before);
    });
});
