<?php

declare(strict_types=1);

use App\Inventories\Imports\ImportStatus;
use App\Models\Import;
use App\Models\InventoryField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../../Support/imports.php';
require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * An import can create fields, which is the structure of an inventory: `manage`, not `write`. A token that may write is not given the
 * structure by a mapping, whatever the role of its owner.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * An inventory with a field named "Name", and the CSV (Name, Price, Notes) of an import of it.
 *
 * @return array{case: array<string, mixed>, name: InventoryField, path: string}
 */
function tokenImportCase(): array
{
    $case = importCase();
    $name = fieldIn($case['inventory'], 'text', 'Name');

    return ['case' => $case, 'name' => $name, 'path' => importFile(importCsv([['Name', 'Price', 'Notes'], ['Drill', '30', 'Cordless']]))];
}

describe('an import made with a token', function (): void {
    it('is not proposed new fields when the token cannot manage, and the file is still read', function (): void {
        $made = tokenImportCase();
        $token = tokenFor($made['case']['team']['owner'], ['read', 'write']);

        $response = tokenUpload($this, $token, importUri($made['case']['inventory']), importUploaded($made['path'], 'stock.csv'))->assertCreated();

        expect(array_column($response->json('data.mapping'), 'target'))->toBe(['field', 'ignore', 'ignore'])
            ->and($response->json('data.columns'))->toHaveCount(3);
    });

    it('is proposed new fields when the token can manage', function (): void {
        $made = tokenImportCase();
        $token = tokenFor($made['case']['team']['owner'], ['write', 'manage']);

        $response = tokenUpload($this, $token, importUri($made['case']['inventory']), importUploaded($made['path'], 'stock.csv'))->assertCreated();

        expect(array_column($response->json('data.mapping'), 'target'))->toBe(['field', 'new', 'new']);
    });

    it('is proposed new fields from a session, as before: the role decides', function (): void {
        $made = tokenImportCase();

        $response = importPost($this, $made['case']['team']['owner'], $made['case']['inventory'], $made['path'])->assertCreated();

        expect(array_column($response->json('data.mapping'), 'target'))->toBe(['field', 'new', 'new']);
    });

    it('is proposed new fields from a token of "*", which can do everything', function (): void {
        $made = tokenImportCase();
        $token = tokenFor($made['case']['team']['owner'], ['*']);

        $response = tokenUpload($this, $token, importUri($made['case']['inventory']), importUploaded($made['path'], 'stock.csv'))->assertCreated();

        expect(array_column($response->json('data.mapping'), 'target'))->toBe(['field', 'new', 'new']);
    });

    it('is not proposed new fields either when the file is read again, with a token that cannot manage', function (): void {
        $made = tokenImportCase();
        $owner = $made['case']['team']['owner'];

        // Sent from a session, which proposes new fields: the token that reads it again must not keep them.
        $sent = importPost($this, $owner, $made['case']['inventory'], $made['path'])->assertCreated();
        $uri = importUri($made['case']['inventory'], Import::query()->where('ulid', $sent->json('data.id'))->firstOrFail());

        $again = tokenCall($this, tokenFor($owner, ['read', 'write']), 'PATCH', $uri, ['has_header' => true, 'delimiter' => 'comma'])->assertOk();
        expect(array_column($again->json('data.mapping'), 'target'))->toBe(['field', 'ignore', 'ignore']);

        $manage = tokenCall($this, tokenFor($owner, ['write', 'manage']), 'PATCH', $uri, ['has_header' => true, 'delimiter' => 'comma'])->assertOk();
        expect(array_column($manage->json('data.mapping'), 'target'))->toBe(['field', 'new', 'new']);
    });

    it('cannot be started with a mapping that creates a field unless the token can manage', function (): void {
        Queue::fake();
        $made = tokenImportCase();
        $owner = $made['case']['team']['owner'];

        // The mapping is the one a session proposed: a token that may write must not be able to start it.
        $sent = importPost($this, $owner, $made['case']['inventory'], $made['path'])->assertCreated();
        $import = Import::query()->where('ulid', $sent->json('data.id'))->firstOrFail();
        $mapping = $sent->json('data.mapping');
        $start = importUri($made['case']['inventory'], $import, '/start');

        tokenCall($this, tokenFor($owner, ['read', 'write']), 'POST', $start, ['mapping' => $mapping])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_manage_fields')
            ->assertJsonValidationErrors('mapping');

        Queue::assertNothingPushed();
        expect(importStatus($import))->toBe(ImportStatus::Uploaded)->and(InventoryField::query()->count())->toBe(1);

        tokenCall($this, tokenFor($owner, ['write', 'manage']), 'POST', $start, ['mapping' => $mapping])->assertStatus(202);

        expect(importStatus($import))->toBe(ImportStatus::Pending);
    });

    it('can be started with a mapping onto the fields that exist, without the ability to manage', function (): void {
        Queue::fake();
        $made = tokenImportCase();
        $owner = $made['case']['team']['owner'];
        $token = tokenFor($owner, ['write']);

        $sent = tokenUpload($this, $token, importUri($made['case']['inventory']), importUploaded($made['path'], 'stock.csv'))->assertCreated();
        $import = Import::query()->where('ulid', $sent->json('data.id'))->firstOrFail();

        tokenCall($this, $token, 'POST', importUri($made['case']['inventory'], $import, '/start'), ['mapping' => [importToField(0, $made['name']->ulid), ['column' => 1, 'target' => 'ignore']]])
            ->assertStatus(202);

        expect(importStatus($import))->toBe(ImportStatus::Pending);
    });

    it('creates the fields of a mapping started with a token that can manage, once the job ran', function (): void {
        Queue::fake();
        $made = tokenImportCase();
        $owner = $made['case']['team']['owner'];
        $token = tokenFor($owner, ['write', 'manage']);

        $sent = tokenUpload($this, $token, importUri($made['case']['inventory']), importUploaded($made['path'], 'stock.csv'))->assertCreated();
        $import = Import::query()->where('ulid', $sent->json('data.id'))->firstOrFail();

        tokenCall($this, $token, 'POST', importUri($made['case']['inventory'], $import, '/start'), ['mapping' => $sent->json('data.mapping')])->assertStatus(202);

        $done = importDone($import);

        expect($done->status)->toBe(ImportStatus::Completed)->and($done->fields_created)->toBe(2)->and(InventoryField::query()->count())->toBe(3);
    });
});
