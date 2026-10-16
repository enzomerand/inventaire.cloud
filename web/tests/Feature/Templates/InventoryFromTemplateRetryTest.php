<?php

declare(strict_types=1);

use App\Models\Inventory;
use App\Models\InventoryField;
use Tests\Support\CommitsDatabaseChanges;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

// Committed to the database, not wrapped in a transaction: a rolled back attempt is only a real rollback when the transaction
// that made the inventory is the outermost one (inside the transaction of a test, the framework keeps what a lost deadlock
// wrote). The test removes what it wrote.
uses(CommitsDatabaseChanges::class);

beforeEach(fn () => templateFixtures());

afterEach(fn () => removeConcurrencyRows());

it('is run again from the start, as a whole, when the database picks it as the loser of a deadlock', function (): void {
    $team = inventoryTeam();
    $tries = 0;
    InventoryField::creating(function () use (&$tries): void {
        // The third field of the first attempt.
        if (++$tries === 3) {
            throw lostDeadlock();
        }
    });

    $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => 'Tools', 'template' => 'sample-tools'], ['Accept-Language' => 'en'])
        ->assertCreated();

    // The first attempt made an inventory and two fields and lost them all; the second made the six fields of the template.
    expect($tries)->toBe(3 + 6)
        ->and(Inventory::query()->count())->toBe(1)
        ->and(InventoryField::query()->count())->toBe(6)
        ->and(Inventory::query()->where('ulid', $response->json('data.id'))->sole()->fields()->count())->toBe(6);
});

it('leaves no inventory behind when a field fails for good', function (): void {
    $team = inventoryTeam();
    InventoryField::creating(function (InventoryField $field): void {
        if ($field->name === 'Condition') {
            throw new RuntimeException('The disk is full.');
        }
    });

    inventoryCall($this, $team['owner'], 'POST', "/api/v1/workspaces/{$team['workspace']->ulid}/inventories", ['name' => 'Tools', 'template' => 'sample-tools'], ['Accept-Language' => 'en'])
        ->assertStatus(500);

    expect(Inventory::query()->count())->toBe(0)->and(InventoryField::query()->count())->toBe(0);
});
