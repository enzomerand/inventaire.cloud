<?php

declare(strict_types=1);

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\ImportNotEditableException;
use App\Inventories\Imports\Imports;
use App\Inventories\Imports\ImportStatus;
use App\Jobs\ImportItems;
use App\Jobs\PurgeStoredFiles;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/imports.php';

/*
 * The API of the imports: sending a file and what comes back, the limits, reading it again, starting it, the list and the reading of
 * a person's own imports, the deletion, and who may do what.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The keys of an import as the API gives it, in order.
 */
const IMPORT_KEYS = ['id', 'format', 'status', 'error', 'file_name', 'size', 'options', 'columns', 'mapping', 'rows_total', 'rows_imported', 'rows_failed', 'fields_created', 'errors', 'created_at', 'completed_at', 'expires_at'];

/**
 * An inventory with two fields, and a CSV a French spreadsheet would save for it, with columns the inventory has not.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>, csv: string}
 */
function importFixture(): array
{
    $case = importCase();
    $fields = [
        'name' => fieldIn($case['inventory'], 'text', 'Name'),
        'quantity' => fieldIn($case['inventory'], 'number', 'Quantity', ['decimals' => 2]),
    ];
    $csv = importFile(importCsv([
        ['Name', 'Quantity (kg)', 'Price', 'In stock', 'Bought', 'Identifier', 'Notes'],
        ['Drill', '2,5', '12,50 €', 'oui', '15/03/2026', 'abc', 'first'],
        ['Saw', '1', '9 €', 'non', '16/03/2026', 'def', "two\nlines"],
    ], ';'));

    return $case + ['fields' => $fields, 'csv' => $csv];
}

describe('without signing in', function (): void {
    it('answers 401 in JSON on every endpoint', function (string $method, string $path): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);
        $uri = str_replace(['{inventory}', '{import}'], [$case['inventory']->ulid, $import->ulid], $path);

        $this->call($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    })->with([
        ['GET', '/api/v1/inventories/{inventory}/imports'],
        ['POST', '/api/v1/inventories/{inventory}/imports'],
        ['GET', '/api/v1/inventories/{inventory}/imports/{import}'],
        ['PATCH', '/api/v1/inventories/{inventory}/imports/{import}'],
        ['POST', '/api/v1/inventories/{inventory}/imports/{import}/start'],
        ['DELETE', '/api/v1/inventories/{inventory}/imports/{import}'],
    ]);
});

describe('sending a file', function (): void {
    it('answers 201 with the import, read: its columns, the type of each, and where each goes', function (): void {
        $case = importFixture();

        $response = importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'], name: 'stock.csv')->assertCreated();
        $import = Import::query()->sole();

        expect(array_keys($response->json('data')))->toBe(IMPORT_KEYS)
            ->and($response->json('data.id'))->toBe($import->ulid)
            ->and($response->json('data.format'))->toBe('csv')
            ->and($response->json('data.status'))->toBe('uploaded')
            ->and($response->json('data.error'))->toBeNull()
            ->and($response->json('data.file_name'))->toBe('stock.csv')
            ->and($response->json('data.size'))->toBe((int) filesize($case['csv']))
            ->and($response->json('data.options'))->toBe(['has_header' => true, 'delimiter' => 'semicolon', 'encoding' => 'utf-8', 'decimal' => 'comma', 'date_order' => 'dmy', 'on_error' => 'abort'])
            ->and($response->json('data.columns'))->toBe([
                ['index' => 0, 'header' => 'Name', 'samples' => ['Drill', 'Saw'], 'guess' => 'text', 'fraction_like' => false],
                ['index' => 1, 'header' => 'Quantity (kg)', 'samples' => ['2,5', '1'], 'guess' => 'number', 'fraction_like' => false],
                ['index' => 2, 'header' => 'Price', 'samples' => ['12,50 €', '9 €'], 'guess' => 'money', 'fraction_like' => false],
                ['index' => 3, 'header' => 'In stock', 'samples' => ['oui', 'non'], 'guess' => 'boolean', 'fraction_like' => false],
                ['index' => 4, 'header' => 'Bought', 'samples' => ['15/03/2026', '16/03/2026'], 'guess' => 'date', 'fraction_like' => false],
                ['index' => 5, 'header' => 'Identifier', 'samples' => ['abc', 'def'], 'guess' => 'text', 'fraction_like' => false],
                ['index' => 6, 'header' => 'Notes', 'samples' => ['first', 'two lines'], 'guess' => 'textarea', 'fraction_like' => false],
            ])
            ->and($response->json('data.mapping'))->toBe([
                ['column' => 0, 'target' => 'field', 'field' => $case['fields']['name']->ulid, 'type' => null, 'name' => null],
                ['column' => 1, 'target' => 'field', 'field' => $case['fields']['quantity']->ulid, 'type' => null, 'name' => null],
                ['column' => 2, 'target' => 'new', 'field' => null, 'type' => 'money', 'name' => 'Price'],
                ['column' => 3, 'target' => 'new', 'field' => null, 'type' => 'boolean', 'name' => 'In stock'],
                ['column' => 4, 'target' => 'new', 'field' => null, 'type' => 'date', 'name' => 'Bought'],
                ['column' => 5, 'target' => 'ignore', 'field' => null, 'type' => null, 'name' => null],
                ['column' => 6, 'target' => 'new', 'field' => null, 'type' => 'textarea', 'name' => 'Notes'],
            ])
            ->and([$response->json('data.rows_total'), $response->json('data.rows_imported'), $response->json('data.rows_failed'), $response->json('data.fields_created'), $response->json('data.errors'), $response->json('data.completed_at')])->toBe([null, null, null, null, null, null])
            ->and($response->json('data.expires_at'))->not->toBeNull();
    });

    it('keeps the file with the private files, under the folder of the inventory, until the import ends', function (): void {
        $case = importFixture();

        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        $path = AttachmentStorage::importDirectory($case['team']['workspace']->ulid, $case['inventory']->ulid, $import->ulid).'/source.csv';

        expect($import->path)->toBe($path)->and($import->disk)->toBe(ATTACHMENT_TEST_DISK)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->get($path))->toBe((string) file_get_contents($case['csv']))
            ->and($import->user_id)->toBe($case['team']['owner']->id)
            ->and($import->locale)->toBe('en');
    });

    it('is open to the owners and the editors of the workspace, and to them only', function (string $role, int $status): void {
        $case = importFixture();

        importPost($this, $case['team'][$role], $case['inventory'], $case['csv'])->assertStatus($status);
    })->with([['owner', 201], ['editor', 201], ['viewer', 403], ['stranger', 404]]);

    it('answers 422 on the file, with a code, when it is not one the import reads', function (string $name, string $bytes, string $code): void {
        $case = importFixture();

        importPost($this, $case['team']['owner'], $case['inventory'], importFile($bytes, pathinfo($name, PATHINFO_EXTENSION)), name: $name)
            ->assertStatus(422)
            ->assertJsonPath('code', $code)
            ->assertJsonValidationErrors('file');

        expect(Import::query()->count())->toBe(0);
    })->with([
        'a legacy spreadsheet' => ['stock.xls', "\xD0\xCF\x11\xE0", 'unrecognized_format'],
        'a text with no extension' => ['stock', 'a,b', 'unrecognized_format'],
        'a PNG called a CSV' => ['stock.csv', "\x89PNG\r\n\x1A\n\x00\x00", 'unrecognized_format'],
        'a text called a spreadsheet' => ['stock.xlsx', 'a,b', 'unrecognized_format'],
        'an empty CSV' => ['stock.csv', '', 'empty_file'],
        'a CSV of blank lines' => ['stock.csv', "\r\n\r\n", 'empty_file'],
    ]);

    it('answers 422 when it is too large, and says how large', function (): void {
        $case = importFixture();
        config(['inventories.import_max_size' => 1024]);

        importPost($this, $case['team']['owner'], $case['inventory'], importFile(str_repeat('a,b'."\n", 1000)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', 'The file must not be larger than 1 KB.');
    });

    it('answers 422 when a spreadsheet is a bomb, or uses too many columns', function (): void {
        $case = importFixture();
        $sheet = importSpreadsheet('xlsx', [['a', 'b', 'c', 'd'], [1, 2, 3, 4]]);

        config(['inventories.import_max_columns' => 3]);
        importPost($this, $case['team']['owner'], $case['inventory'], $sheet)->assertStatus(422)->assertJsonPath('code', 'too_many_columns');

        config(['inventories.import_max_columns' => 200, 'inventories.import_max_unpacked_size' => 100]);
        importPost($this, $case['team']['owner'], $case['inventory'], $sheet)->assertStatus(422)->assertJsonPath('code', 'unpacked_too_large');
    });

    it('answers 422 when a sheet holds more cells than an import can build, the empty ones of its formatting counted', function (): void {
        $case = importFixture();
        // A thousand cells in a row, given 5 000 times: 5 million, in a file of a few kilobytes.
        $sheet = importOdsAfterRow(importSpreadsheet('ods', [['a', 'b']]), 1, '<table:table-row table:number-rows-repeated="5000"><table:table-cell office:value-type="string" table:number-columns-repeated="1000"><text:p>x</text:p></table:table-cell></table:table-row>');

        importPost($this, $case['team']['owner'], $case['inventory'], $sheet)
            ->assertStatus(422)
            ->assertJsonPath('code', 'too_many_cells')
            ->assertJsonPath('errors.file.0', 'This file holds more than 4,000,000 cells, counting the empty ones that a spreadsheet keeps for its formatting. Delete the rows and columns you do not use, then send it again.');

        expect(Import::query()->count())->toBe(0);
    });

    it('is read as it is told: without a header, in another encoding, with another separator', function (): void {
        $case = importFixture();
        $csv = importFile(mb_convert_encoding(importCsv([['Prénom', 'Ville'], ['Élodie', 'Besançon']], ';'), 'Windows-1252', 'UTF-8'));

        $found = importPost($this, $case['team']['owner'], $case['inventory'], $csv)->assertCreated();
        $told = importPost($this, $case['team']['owner'], $case['inventory'], $csv, ['has_header' => 'false', 'delimiter' => 'comma', 'encoding' => 'utf-8'])->assertCreated();

        expect($found->json('data.options.encoding'))->toBe('windows-1252')
            ->and($found->json('data.options.delimiter'))->toBe('semicolon')
            ->and(array_column($found->json('data.columns'), 'header'))->toBe(['Prénom', 'Ville'])
            ->and($told->json('data.options.has_header'))->toBeFalse()
            ->and($told->json('data.options.delimiter'))->toBe('comma')
            ->and(array_column($told->json('data.columns'), 'header'))->toBe(['Column A']);
    });

    it('answers 422 for an option it does not know', function (string $field, string $value): void {
        $case = importFixture();

        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'], [$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([['delimiter', 'colon'], ['encoding', 'latin-9'], ['has_header', 'maybe']]);

    it('makes up the name of a column without a header, and a name that is free for two columns with the same header', function (): void {
        $case = importCase();
        fieldIn($case['inventory'], 'text', 'Price');
        $csv = importFile(importCsv([['Code', '', 'Code', 'price (usd)'], ['a', 'b', 'c', '1']]));

        $mapping = importPost($this, $case['team']['owner'], $case['inventory'], $csv)->assertCreated()->json('data.mapping');

        expect(array_column($mapping, 'name'))->toBe(['Code', 'Column B', 'Code 2', null])
            ->and($mapping[3]['target'])->toBe('field');
    });

    it('limits the imports a person has under way for an inventory, and counts the others apart', function (): void {
        $case = importFixture();
        config(['inventories.max_active_imports' => 2]);

        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $refused = importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertStatus(409)->assertJsonPath('code', 'import_limit_reached')->assertJsonPath('limit', 2);

        // Another person, another inventory, a finished import and an expired one do not count.
        importPost($this, $case['team']['editor'], $case['inventory'], $case['csv'])->assertCreated();
        importPost($this, $case['team']['owner'], inventoryIn($case['team']['workspace'], 'Other'), $case['csv'])->assertCreated();
        Import::query()->where('user_id', $case['team']['owner']->id)->where('inventory_id', $case['inventory']->id)->first()?->forceFill(['status' => ImportStatus::Completed])->save();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        Import::query()->where('user_id', $case['team']['owner']->id)->where('inventory_id', $case['inventory']->id)->where('status', 'uploaded')->update(['expires_at' => now()->subMinute()]);
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();

        expect($refused->json('message'))->toBe('You already have too many imports under way for this inventory. Cancel one or wait for it to end, then try again.')
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toHaveCount(Import::query()->count());
    });

    it('does not keep a file for an import that was refused', function (): void {
        $case = importFixture();
        config(['inventories.max_active_imports' => 1]);

        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertStatus(409);

        expect(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toHaveCount(1);
    });

    it('writes the row before the file, and takes the row back when the file cannot be written', function (): void {
        $case = importFixture();
        $seen = null;
        // The disk refuses the file; what the table holds at that moment is what a crash would leave behind.
        attachmentDiskProxy()->shouldReceive('put')->andReturnUsing(function () use (&$seen): bool {
            $seen = Import::query()->get(['status', 'path'])->map(fn (Import $import): array => [$import->status->value, $import->path])->all();

            return false;
        });

        expect(fn () => app(Imports::class)->upload($case['inventory'], $case['team']['owner'], importUploaded($case['csv'], 'stock.csv'), [], true))
            ->toThrow(RuntimeException::class);

        // The row was there without a path (so the pruner finds the folder of a file that was half written), then it went.
        expect($seen)->toBe([['uploaded', null]])
            ->and(Import::query()->count())->toBe(0)
            ->and(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });

    it('is paced: the files one person sends in a minute are limited', function (): void {
        $case = importFixture();
        config(['inventories.imports_per_minute' => 2, 'inventories.max_active_imports' => 10]);

        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertStatus(429);
        // The count is the person's own.
        importPost($this, $case['team']['editor'], $case['inventory'], $case['csv'])->assertCreated();
    });

    it('reads a spreadsheet', function (string $format): void {
        $case = importFixture();
        $sheet = importSpreadsheet($format, [['Name', 'Quantity', 'Bought', 'Open'], ['Drill', 2.5, new DateTimeImmutable('2026-03-15'), new DateInterval('PT7H5M')]]);

        $response = importPost($this, $case['team']['owner'], $case['inventory'], $sheet)->assertCreated();

        expect($response->json('data.format'))->toBe($format)
            ->and($response->json('data.options.delimiter'))->toBeNull()
            ->and($response->json('data.options.encoding'))->toBeNull()
            ->and(array_column($response->json('data.columns'), 'guess'))->toBe(['text', 'number', 'date', 'time'])
            ->and($response->json('data.columns.2.samples'))->toBe(['2026-03-15'])
            ->and($response->json('data.columns.3.samples'))->toBe(['07:05']);
    })->with(['xlsx', 'ods']);
});

describe('the list and the reading of imports', function (): void {
    it('lists the imports of the person for the inventory whose time is not up, the newest first', function (): void {
        $case = importFixture();
        $owner = $case['team']['owner'];
        $make = fn ($user, $inventory, array $attributes = []) => Import::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $user->id] + $attributes);
        $old = $make($owner, $case['inventory'], ['created_at' => now()->subHour()]);
        $new = $make($owner, $case['inventory'], ['created_at' => now()]);
        $make($owner, $case['inventory'], ['expires_at' => now()->subMinute()]);
        $make($case['team']['editor'], $case['inventory']);
        $make($owner, inventoryIn($case['team']['workspace'], 'Other'));
        $running = $make($owner, $case['inventory'], ['status' => ImportStatus::Running, 'expires_at' => null, 'created_at' => now()->subMinute()]);

        $ids = inventoryCall($this, $owner, 'GET', importUri($case['inventory']))->assertOk()->json('data.*.id');

        expect($ids)->toBe([$new->ulid, $running->ulid, $old->ulid]);
    });

    it('lists twenty at most', function (): void {
        $case = importFixture();
        Import::factory()->count(22)->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        expect(inventoryCall($this, $case['team']['owner'], 'GET', importUri($case['inventory']))->assertOk()->json('data'))->toHaveCount(20);
    });

    it('reads one import, with the same keys', function (): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        $response = inventoryCall($this, $case['team']['owner'], 'GET', importUri($case['inventory'], $import))->assertOk();

        expect(array_keys($response->json('data')))->toBe(IMPORT_KEYS)->and($response->json('data.id'))->toBe($import->ulid);
    });

    it('tells a failure in words', function (): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'status' => ImportStatus::Failed, 'error' => 'invalid_rows']);
        $unknown = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'status' => ImportStatus::Failed, 'error' => 'a_later_code']);

        expect(inventoryCall($this, $case['team']['owner'], 'GET', importUri($case['inventory'], $import))->json('data.error'))->toStartWith('Some rows are not valid')
            ->and(inventoryCall($this, $case['team']['owner'], 'GET', importUri($case['inventory'], $unknown))->json('data.error'))->toBe('The import could not be done. Try again.');
    });

    it('is the business of the person who sent the file: another person, even in the workspace, gets a 404', function (string $method, string $suffix): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        foreach (['editor', 'owner'] as $role) {
            $as = $case['team'][$role];

            if ($role === 'owner') {
                continue;
            }

            inventoryCall($this, $as, $method, importUri($case['inventory'], $import, $suffix), ['mapping' => []])->assertNotFound();
        }

        expect(Import::query()->count())->toBe(1);
    })->with([['GET', ''], ['PATCH', ''], ['POST', '/start'], ['DELETE', '']]);

    it('is a 404 from another inventory, a viewer is refused and a stranger told it is not there', function (): void {
        $case = importFixture();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        inventoryCall($this, $case['team']['owner'], 'GET', importUri($other, $import))->assertNotFound();
        inventoryCall($this, $case['team']['viewer'], 'GET', importUri($case['inventory'], $import))->assertForbidden();
        inventoryCall($this, $case['team']['viewer'], 'GET', importUri($case['inventory']))->assertForbidden();
        inventoryCall($this, $case['team']['stranger'], 'GET', importUri($case['inventory'], $import))->assertNotFound();
        inventoryCall($this, $case['team']['stranger'], 'GET', importUri($case['inventory']))->assertNotFound();
    });
});

describe('reading the file again', function (): void {
    it('makes the columns and the proposal anew with the options it is given', function (): void {
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();

        $response = inventoryCall($this, $case['team']['owner'], 'PATCH', importUri($case['inventory'], $import), ['has_header' => false, 'delimiter' => 'tab'])->assertOk();

        expect($response->json('data.options.has_header'))->toBeFalse()
            ->and($response->json('data.options.delimiter'))->toBe('tab')
            // A new separator brings the decimal sign that goes with it (nothing in the one column says otherwise).
            ->and($response->json('data.options.decimal'))->toBe('point')
            ->and($response->json('data.columns'))->toHaveCount(1)
            ->and($response->json('data.columns.0.header'))->toBe('Column A')
            ->and($response->json('data.columns.0.samples'))->toBe(['Name;Quantity (kg);Price;In stock;Bought;Identifier;Notes', 'Drill;2,5;12,50 €;oui;15/03/2026;abc;first', 'Saw;1;9 €;non;16/03/2026;def;"two'])
            ->and($response->json('data.mapping'))->toBe([['column' => 0, 'target' => 'new', 'field' => null, 'type' => 'text', 'name' => 'Column A']])
            ->and($import->refresh()->columns)->toHaveCount(1);
    });

    it('keeps the options it is not given, and keeps the decimal sign the person chose', function (): void {
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();

        $response = inventoryCall($this, $case['team']['owner'], 'PATCH', importUri($case['inventory'], $import), ['encoding' => 'windows-1252', 'delimiter' => 'comma', 'decimal' => 'comma', 'date_order' => 'dmy', 'on_error' => 'skip'])->assertOk();

        expect($response->json('data.options'))->toBe(['has_header' => true, 'delimiter' => 'comma', 'encoding' => 'windows-1252', 'decimal' => 'comma', 'date_order' => 'dmy', 'on_error' => 'skip']);
    });

    it('is refused once it was started, and when its time is up', function (): void {
        $case = importFixture();
        $started = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'status' => ImportStatus::Pending]);
        $expired = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'expires_at' => now()->subMinute()]);

        inventoryCall($this, $case['team']['owner'], 'PATCH', importUri($case['inventory'], $started), ['has_header' => false])->assertStatus(409)->assertJsonPath('code', 'import_not_editable');
        inventoryCall($this, $case['team']['owner'], 'PATCH', importUri($case['inventory'], $expired), ['has_header' => false])->assertNotFound()->assertJsonPath('code', 'import_expired');
    });

    it('answers 404, not that it was started, when the import was deleted while the file was read again', function (): void {
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        importAfterStatement('/^select .* from [`"]inventory_fields[`"]/i', fn () => DB::table('imports')->where('id', $import->id)->delete());

        $response = inventoryCall($this, $case['team']['owner'], 'PATCH', importUri($case['inventory'], $import), ['has_header' => false])->assertNotFound();

        expect($response->json('code'))->not->toBe('import_not_editable');
    });

    it('answers 422 for an option it does not know', function (): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        inventoryCall($this, $case['team']['owner'], 'PATCH', importUri($case['inventory'], $import), ['delimiter' => 'colon'])->assertStatus(422)->assertJsonValidationErrors('delimiter');
    });
});

describe('starting an import', function (): void {
    it('answers 202 with the import pending, keeps the mapping and the options, and queues the job once', function (): void {
        Queue::fake();
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        $mapping = [importToField(0, $case['fields']['name']->ulid), importToField(1, strtoupper($case['fields']['quantity']->ulid)), importToNew(2, 'money', ' Price '), ['column' => 5, 'target' => 'ignore']];

        $response = inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), ['mapping' => $mapping, 'decimal' => 'point', 'date_order' => 'ymd', 'on_error' => 'skip'])->assertStatus(202);

        expect($response->json('data.status'))->toBe('pending')
            ->and($response->json('data.options'))->toBe(['has_header' => true, 'delimiter' => 'semicolon', 'encoding' => 'utf-8', 'decimal' => 'point', 'date_order' => 'ymd', 'on_error' => 'skip'])
            ->and($response->json('data.mapping'))->toBe([
                ['column' => 0, 'target' => 'field', 'field' => $case['fields']['name']->ulid, 'type' => null, 'name' => null],
                ['column' => 1, 'target' => 'field', 'field' => $case['fields']['quantity']->ulid, 'type' => null, 'name' => null],
                ['column' => 2, 'target' => 'new', 'field' => null, 'type' => 'money', 'name' => 'Price'],
                ['column' => 5, 'target' => 'ignore', 'field' => null, 'type' => null, 'name' => null],
            ])
            ->and($response->json('data.expires_at'))->toBeNull()
            ->and(importStatus($import))->toBe(ImportStatus::Pending);

        Queue::assertPushed(ImportItems::class, 1);
        Queue::assertPushedOn('exports', ImportItems::class);
    });

    it('keeps the options of the import for the ones it is not given', function (): void {
        Queue::fake();
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();

        $response = inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), ['mapping' => [importToField(0, $case['fields']['name']->ulid)]])->assertStatus(202);

        expect($response->json('data.options.decimal'))->toBe('comma')->and($response->json('data.options.on_error'))->toBe('abort');
    });

    it('answers 422 on the mapping, with a code, when it cannot be started', function (callable $mapping, string $code): void {
        Queue::fake();
        $case = importFixture();
        $required = fieldIn($case['inventory'], 'text', 'Reference', required: true);
        $photos = fieldIn($case['inventory'], 'photo', 'Photos');
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        $body = $mapping($case['fields'] + ['required' => $required, 'photos' => $photos]);

        inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), ['mapping' => $body])
            ->assertStatus(422)
            ->assertJsonPath('code', $code)
            ->assertJsonValidationErrors('mapping');

        Queue::assertNothingPushed();
        expect(importStatus($import))->toBe(ImportStatus::Uploaded);
    })->with([
        'a column the file does not have' => [fn (array $f) => [importToField(9, $f['required']->ulid)], 'unknown_column'],
        'a column given twice' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(0, 'text', 'Again')], 'duplicate_column'],
        'a field that does not exist' => [fn (array $f) => [importToField(0, '01ARZ3NDEKTSV4RRFFQ69G5FAV')], 'unknown_field'],
        'a photo field' => [fn (array $f) => [importToField(0, $f['photos']->ulid), importToField(1, $f['required']->ulid)], 'field_not_importable'],
        'two columns to a field' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToField(1, $f['required']->ulid)], 'duplicate_field'],
        'a new field of a type that cannot be' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(1, 'photo', 'Pictures')], 'invalid_field_type'],
        'a new multiple choice' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(1, 'multiselect', 'Labels')], 'invalid_field_type'],
        'a type that does not exist' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(1, 'colour', 'Tint')], 'invalid_field_type'],
        'a new field with a name that is too long' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(1, 'text', str_repeat('a', 121))], 'invalid_field_name'],
        'a new field with a name with a line break' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(1, 'text', "a\nb")], 'invalid_field_name'],
        'two new fields with the same name' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(1, 'text', 'Label'), importToNew(2, 'text', 'LABEL')], 'duplicate_field_name'],
        'a new field with the name of a field' => [fn (array $f) => [importToField(0, $f['required']->ulid), importToNew(1, 'text', 'name')], 'field_name_taken'],
        'a required field with no column' => [fn (array $f) => [importToField(0, $f['name']->ulid)], 'required_field_unmapped'],
        'nothing at all' => [fn (array $f) => [], 'nothing_mapped'],
        'only ignored columns' => [fn (array $f) => [['column' => 0, 'target' => 'ignore']], 'nothing_mapped'],
    ]);

    it('answers 422 for a mapping of a shape it cannot read', function (mixed $mapping): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'columns' => [['index' => 0, 'header' => 'a', 'samples' => [], 'guess' => 'text', 'fraction_like' => false]]]);

        $errors = inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), ['mapping' => $mapping])->assertStatus(422)->json('errors');

        expect(array_keys($errors)[0])->toStartWith('mapping');
    })->with([
        'a text' => ['all'],
        'a negative column' => [[['column' => -1, 'target' => 'ignore']]],
        'a blank name' => [[['column' => 0, 'target' => 'new', 'type' => 'text', 'name' => '   ']]],
        'an entry that is no entry' => [['a']],
        'a target that does not exist' => [[['column' => 0, 'target' => 'maybe']]],
        'a field target with no field' => [[['column' => 0, 'target' => 'field']]],
        'a new target with no name' => [[['column' => 0, 'target' => 'new', 'type' => 'text']]],
        'a column that is not a number' => [[['column' => 'a', 'target' => 'ignore']]],
    ]);

    it('answers 422 when the mapping is missing', function (): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'))->assertStatus(422)->assertJsonValidationErrors('mapping');
    });

    it('answers 409 when the new fields would take the inventory past the number of fields it may have', function (): void {
        Queue::fake();
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        config(['inventories.max_fields' => 3]);

        inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), ['mapping' => [importToField(0, $case['fields']['name']->ulid), importToNew(2, 'money', 'Price'), importToNew(3, 'boolean', 'In stock')]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'field_limit_reached')
            ->assertJsonPath('limit', 3);

        Queue::assertNothingPushed();
    });

    it('is refused once it was started, and when its time is up', function (): void {
        Queue::fake();
        $case = importFixture();
        $mapping = [importToField(0, $case['fields']['name']->ulid)];
        $columns = [['index' => 0, 'header' => 'Name', 'samples' => [], 'guess' => 'text', 'fraction_like' => false]];
        $started = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'status' => ImportStatus::Pending, 'columns' => $columns]);
        $expired = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'expires_at' => now()->subMinute(), 'columns' => $columns]);

        inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $started, '/start'), ['mapping' => $mapping])->assertStatus(409)->assertJsonPath('code', 'import_not_editable');
        inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $expired, '/start'), ['mapping' => $mapping])->assertNotFound()->assertJsonPath('code', 'import_expired');

        Queue::assertNothingPushed();
    });

    it('answers 404, not that it was started, when the import was deleted while the request went on', function (): void {
        Queue::fake();
        $case = importFixture();
        $sent = importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        // The person deletes the import (or the inventory is, with it) after this request loaded it and before it locks it to start it.
        importAfterStatement('/^select .* from [`"]inventory_fields[`"]/i', fn () => DB::table('imports')->where('id', $import->id)->delete());

        $response = inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), ['mapping' => $sent->json('data.mapping')])->assertNotFound();

        expect($response->json('code'))->not->toBe('import_not_editable');
        Queue::assertNotPushed(ImportItems::class);
    });

    it('is refused when the file was read again after the copy that is started was loaded', function (): void {
        Queue::fake();
        $case = importFixture();
        $sent = importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $imports = app(Imports::class);
        // A request loads the import, another one reads the file again with other options, then the first goes on with the columns it saw.
        $stale = Import::query()->sole();
        $imports->reanalyze(Import::query()->sole(), $case['inventory'], ['has_header' => false], true);

        expect(fn () => $imports->start($stale, $case['inventory'], $sent->json('data.mapping'), [], true))
            ->toThrow(ImportNotEditableException::class)
            ->and(Import::query()->sole()->status)->toBe(ImportStatus::Uploaded);

        Queue::assertNotPushed(ImportItems::class);
    });

    it('cannot start twice', function (): void {
        Queue::fake();
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        $mapping = ['mapping' => [importToField(0, $case['fields']['name']->ulid)]];

        inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), $mapping)->assertStatus(202);
        inventoryCall($this, $case['team']['owner'], 'POST', importUri($case['inventory'], $import, '/start'), $mapping)->assertStatus(409);

        Queue::assertPushed(ImportItems::class, 1);
    });
});

describe('deleting an import', function (): void {
    it('answers 204 and deletes the file with it', function (string $status): void {
        Queue::fake([PurgeStoredFiles::class]);
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();
        $import->forceFill(['status' => ImportStatus::from($status)])->save();

        inventoryCall($this, $case['team']['owner'], 'DELETE', importUri($case['inventory'], $import))->assertNoContent();

        expect(Import::query()->count())->toBe(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [dirname((string) $import->path)]);
    })->with(['uploaded', 'pending', 'completed', 'failed']);

    it('is refused with a 409 while the job works on it', function (): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'status' => ImportStatus::Running]);

        inventoryCall($this, $case['team']['owner'], 'DELETE', importUri($case['inventory'], $import))->assertStatus(409)->assertJsonPath('code', 'import_running');

        expect(Import::query()->count())->toBe(1);
    });

    it('is refused when the job claimed the import after it was loaded: the deletion wants it not to be running', function (): void {
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'status' => ImportStatus::Pending]);
        // The import is read as pending, and the worker claims it before the deletion is made.
        importAfterStatement('/^select .* from [`"]imports[`"]/i', fn () => DB::table('imports')->where('id', $import->id)->update(['status' => 'running']));

        inventoryCall($this, $case['team']['owner'], 'DELETE', importUri($case['inventory'], $import))->assertStatus(409)->assertJsonPath('code', 'import_running');

        expect(Import::query()->sole()->status)->toBe(ImportStatus::Running);
    });

    it('queues the deletion of the folder of a report that says it has no file, which a worker may have left when it stopped', function (): void {
        Queue::fake([PurgeStoredFiles::class]);
        $case = importFixture();
        $import = Import::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'status' => ImportStatus::Completed, 'path' => null]);

        inventoryCall($this, $case['team']['owner'], 'DELETE', importUri($case['inventory'], $import))->assertNoContent();

        expect(Import::query()->count())->toBe(0);
        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => count($job->directories) === 1 && str_ends_with($job->directories[0], "/imports/{$import->ulid}"));
    });

    it('takes the file off the disk', function (): void {
        $case = importFixture();
        importPost($this, $case['team']['owner'], $case['inventory'], $case['csv'])->assertCreated();
        $import = Import::query()->sole();

        expect(Storage::disk(ATTACHMENT_TEST_DISK)->exists((string) $import->path))->toBeTrue();

        inventoryCall($this, $case['team']['owner'], 'DELETE', importUri($case['inventory'], $import))->assertNoContent();

        expect(Storage::disk(ATTACHMENT_TEST_DISK)->allFiles())->toBe([]);
    });
});
