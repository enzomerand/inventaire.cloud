<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportError;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportGenerator;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ExportStatus;
use App\Inventories\InventoryFields;
use App\Jobs\GenerateExport;
use App\Jobs\PurgeStoredFiles;
use App\Models\Export;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/exports.php';

/*
 * The API of the exports: asking for one, the limits, the list and the reading of a person's own exports, the download, the
 * deletion, and who may do what.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The address of the exports of an inventory, or of one of them.
 */
function exportUri(Inventory $inventory, ?Export $export = null, string $suffix = ''): string
{
    return "/api/v1/inventories/{$inventory->ulid}/exports".($export === null ? '' : "/{$export->ulid}").$suffix;
}

/**
 * Asks for an export the way the web front does.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function exportPost(object $test, ?User $as, Inventory $inventory, array $data = ['format' => 'csv'], array $headers = []): TestResponse
{
    return inventoryCall($test, $as, 'POST', exportUri($inventory), $data, $headers);
}

/**
 * The keys of an export as the API gives it, in order.
 */
const EXPORT_KEYS = ['id', 'format', 'scope', 'status', 'error', 'file_name', 'size', 'items_count', 'created_at', 'completed_at', 'expires_at', 'download_url'];

describe('without signing in', function (): void {
    it('answers 401 in JSON on every endpoint', function (string $method, string $path): void {
        $case = exportFixture();
        $export = exportRow($case['inventory'], $case['team']['owner']);
        $uri = str_replace(['{inventory}', '{export}'], [$case['inventory']->ulid, $export->ulid], $path);

        $this->call($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    })->with([
        ['GET', '/api/v1/inventories/{inventory}/exports'],
        ['POST', '/api/v1/inventories/{inventory}/exports'],
        ['GET', '/api/v1/inventories/{inventory}/exports/{export}'],
        ['GET', '/api/v1/inventories/{inventory}/exports/{export}/download'],
        ['DELETE', '/api/v1/inventories/{inventory}/exports/{export}'],
    ]);
});

describe('asking for an export', function (): void {
    it('answers 202 with the export, pending, and queues the job that writes it', function (): void {
        Queue::fake();
        $case = exportFixture();

        $response = exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'xlsx'])->assertStatus(202);
        $export = Export::query()->sole();

        expect(array_keys($response->json('data')))->toBe(EXPORT_KEYS)
            ->and($response->json('data'))->toBe([
                'id' => $export->ulid,
                'format' => 'xlsx',
                'scope' => 'all',
                'status' => 'pending',
                'error' => null,
                'file_name' => null,
                'size' => null,
                'items_count' => null,
                'created_at' => $export->created_at->toIso8601String(),
                'completed_at' => null,
                'expires_at' => null,
                'download_url' => null,
            ])
            ->and($export->user_id)->toBe($case['team']['owner']->id)
            ->and($export->inventory_id)->toBe($case['inventory']->id)
            ->and($export->locale)->toBe('en')
            ->and($export->disk)->toBe(ATTACHMENT_TEST_DISK)
            ->and($export->options)->toBe([]);

        Queue::assertPushed(GenerateExport::class, fn (GenerateExport $job): bool => $job->export->is($export));
        Queue::assertPushed(GenerateExport::class, 1);
        // On the queue of the exports: a worker listens to it after the default one.
        Queue::assertPushedOn('exports', GenerateExport::class);
    });

    it('is open to every member, a viewer too, and to nobody else', function (): void {
        Queue::fake();
        $case = exportFixture();

        foreach (['owner', 'editor', 'viewer'] as $role) {
            exportPost($this, $case['team'][$role], $case['inventory'])->assertStatus(202);
        }

        exportPost($this, $case['team']['stranger'], $case['inventory'])->assertNotFound();

        expect(Export::query()->count())->toBe(3)
            ->and(Export::query()->pluck('user_id')->sort()->values()->all())->toBe(collect([$case['team']['owner']->id, $case['team']['editor']->id, $case['team']['viewer']->id])->sort()->values()->all());
    });

    it('writes the file in the language of the request, and the full flow ends with a file to download', function (): void {
        $case = exportFixture();

        $created = exportPost($this, $case['team']['viewer'], $case['inventory'], ['format' => 'csv'], ['Accept-Language' => 'fr'])->assertStatus(202);
        $export = Export::query()->sole();

        // The queue of the tests is synchronous: the file is written by the time the answer is given.
        expect($export->locale)->toBe('fr')
            ->and($export->status)->toBe(ExportStatus::Completed)
            ->and($created->json('data.status'))->toBe('pending');

        $shown = inventoryCall($this, $case['team']['viewer'], 'GET', exportUri($case['inventory'], $export))->assertOk();

        expect($shown->json('data.status'))->toBe('completed')
            ->and($shown->json('data.items_count'))->toBe(3)
            ->and($shown->json('data.download_url'))->toBe(exportUri($case['inventory'], $export, '/download'));

        $file = attachmentGet($this, $case['team']['viewer'], $shown->json('data.download_url'))->assertOk();

        expect(explode("\r\n", ltrim($file->streamedContent(), "\xEF\xBB\xBF"))[0])->toStartWith('Identifiant;Name;');
    });

    it('keeps only what the format needs: the view for a view, the separator for a CSV, the files for a zip', function (): void {
        Queue::fake();
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $quantity = $case['fields']['quantity']->ulid;
        $query = ['q' => 'drill', 'sort' => $quantity, 'direction' => 'desc', 'filter' => [$quantity => ['min' => '1'], $case['fields']['stock']->ulid => ['is' => 'true'], $case['fields']['name']->ulid => ['empty' => 'false']]];

        exportPost($this, $owner, $case['inventory'], ['format' => 'csv', 'scope' => 'view', 'query' => $query, 'delimiter' => 'tab', 'files' => 'all'])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'], ['format' => 'csv', 'delimiter' => 'semicolon'])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'], ['format' => 'xlsx', 'delimiter' => 'tab', 'files' => 'all'])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'], ['format' => 'json', 'scope' => 'all', 'query' => null])->assertStatus(202);

        [$view, $semicolon, $spreadsheet, $json] = Export::query()->orderBy('id')->get()->all();

        // The view is kept as it came, the strings "true" and "false" included, to be replayed as the list takes it.
        expect($view->scope)->toBe(ExportScope::View)
            // MySQL and MariaDB keep the keys of a JSON object in an order of their own: what matters is what is in it.
            ->and($view->options)->toEqual(['query' => $query, 'delimiter' => 'tab'])
            ->and($semicolon->options)->toBe(['delimiter' => 'semicolon'])
            ->and($spreadsheet->options)->toBe([])
            ->and($json->scope)->toBe(ExportScope::All)
            ->and($json->options)->toBe([]);
    });

    it('keeps the photos as the default files of a zip, and only the parts of a view', function (): void {
        Queue::fake();
        $case = zipCaseForApi();
        $owner = $case['team']['owner'];

        exportPost($this, $owner, $case['inventory'], ['format' => 'zip'])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'files' => 'all'])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'], ['format' => 'csv', 'scope' => 'view', 'query' => ['q' => 'drill', 'page' => 3, 'totals' => ['x'], 'other' => 'ignored']])->assertStatus(202);

        [$photos, $all, $view] = Export::query()->orderBy('id')->get()->all();

        expect($photos->options)->toBe(['files' => 'photos'])
            ->and($all->options)->toBe(['files' => 'all'])
            ->and($view->options)->toBe(['query' => ['q' => 'drill']]);
    });
});

/**
 * The fixture inventory with a photo on the drill.
 *
 * @return array<string, mixed>
 */
function zipCaseForApi(): array
{
    $case = exportFixture();
    attachmentTo($case['inventory'], $case['items']['drill'], attachmentImage('front.png'), $case['team']['owner'], $case['fields']['photos']);

    return $case;
}

describe('a request with a mistake', function (): void {
    it('is refused with the errors on the fields that are wrong', function (array $data, array $keys): void {
        Queue::fake();
        $case = exportFixture();

        $response = exportPost($this, $case['team']['owner'], $case['inventory'], $data)->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe($keys)
            ->and(Export::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'no format' => [[], ['format']],
        'an unknown format' => [['format' => 'docx'], ['format']],
        'a format that is not a word' => [['format' => ['csv']], ['format']],
        'an unknown scope' => [['format' => 'csv', 'scope' => 'everything'], ['scope']],
        'a view without the scope view' => [['format' => 'csv', 'query' => ['q' => 'x']], ['query']],
        'a view for the scope all' => [['format' => 'csv', 'scope' => 'all', 'query' => ['q' => 'x']], ['query']],
        'a view that is not an object' => [['format' => 'csv', 'scope' => 'view', 'query' => 'x'], ['query']],
        'a search that is too long' => [['format' => 'csv', 'scope' => 'view', 'query' => ['q' => str_repeat('a', 201)]], ['query.q']],
        'a filter that is not an object' => [['format' => 'csv', 'scope' => 'view', 'query' => ['filter' => 'x']], ['query.filter']],
        'a sort that is too long' => [['format' => 'csv', 'scope' => 'view', 'query' => ['sort' => str_repeat('a', 65)]], ['query.sort']],
        'a separator that is not one' => [['format' => 'csv', 'delimiter' => 'pipe'], ['delimiter']],
        'files that are not what a zip takes' => [['format' => 'zip', 'files' => 'documents'], ['files']],
        'several mistakes at once' => [['format' => 'docx', 'delimiter' => 'pipe', 'files' => 'x'], ['format', 'delimiter', 'files']],
    ]);

    it('is refused with the errors of the view under query, when it does not hold for the fields of the inventory', function (): void {
        Queue::fake();
        $case = exportFixture();
        $unknown = strtolower((string) Str::ulid());
        $text = $case['fields']['name']->ulid;

        $response = exportPost($this, $case['team']['owner'], $case['inventory'], [
            'format' => 'csv',
            'scope' => 'view',
            'query' => ['sort' => $unknown, 'direction' => 'sideways', 'filter' => [$unknown => ['min' => '1'], $text => ['min' => '1']]],
        ])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toContain('query.sort', 'query.direction', 'query.filter.'.$unknown)
            ->and(array_keys($response->json('errors')))->toContain('query.filter.'.$text.'.min')
            ->and(Export::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('is accepted, for a view that is empty', function (): void {
        Queue::fake();
        $case = exportFixture();

        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'csv', 'scope' => 'view', 'query' => []])->assertStatus(202);
        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'csv', 'scope' => 'view'])->assertStatus(202);

        expect(Export::query()->pluck('options')->all())->toBe([['query' => []], ['query' => []]]);
    });
});

describe('a PDF', function (): void {
    it('is refused beyond the items a PDF may hold, on the format, with the limit', function (): void {
        Queue::fake();
        $case = exportFixture();
        config(['inventories.pdf_max_items' => 400]);

        // 20 fields: 400 items at 8 fields is 160 at 20.
        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'pdf'])->assertStatus(202);

        config(['inventories.pdf_max_items' => 5]);
        $response = exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'pdf'])->assertStatus(422);

        // The inventory has 20 fields and 3 items: 5 items of 8 fields come to 2 items of 20 fields.
        expect(array_keys($response->json('errors')))->toBe(['format'])
            ->and($response->json('code'))->toBe('pdf_too_large')
            ->and($response->json('limit'))->toBe(2)
            ->and($response->json('message'))->toBe('A PDF of this inventory cannot hold more than 2 items: choose another format, or a view with fewer items.')
            ->and(Export::query()->count())->toBe(1);
    });

    it('counts the items of the view, not of the inventory', function (): void {
        Queue::fake();
        $case = exportFixture();
        config(['inventories.pdf_max_items' => 5]);
        $view = ['format' => 'pdf', 'scope' => 'view', 'query' => ['q' => 'drill']];

        exportPost($this, $case['team']['owner'], $case['inventory'], $view)->assertStatus(202);
        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'pdf', 'scope' => 'view', 'query' => []])->assertStatus(422)->assertJsonPath('code', 'pdf_too_large');
        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'csv'])->assertStatus(202);

        expect(Export::query()->count())->toBe(2);
    });

    it('is not refused for an inventory with few fields up to the limit itself', function (): void {
        Queue::fake();
        $case = exportFixture();
        $small = inventoryIn($case['team']['workspace'], 'Small');
        fieldIn($small, 'text', 'Name');
        itemIn($small);
        itemIn($small);
        config(['inventories.pdf_max_items' => 2]);

        exportPost($this, $case['team']['owner'], $small, ['format' => 'pdf'])->assertStatus(202);

        itemIn($small);

        exportPost($this, $case['team']['owner'], $small, ['format' => 'pdf'])->assertStatus(422);
    });
});

describe('a zip', function (): void {
    it('is refused when no item has a photo, on the format', function (): void {
        Queue::fake();
        $case = exportFixture();

        $response = exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'zip'])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['format'])
            ->and($response->json('code'))->toBe('zip_without_files')
            ->and($response->json('message'))->toBe('None of these items has a file to put in the zip.')
            ->and(Export::query()->count())->toBe(0);
    });

    it('is accepted when an item has a photo, and refused when it only has other files unless every file is asked for', function (): void {
        Queue::fake();
        $case = exportFixture();
        $owner = $case['team']['owner'];
        attachmentTo($case['inventory'], $case['items']['formulas'], attachmentPdf('receipt.pdf'), $owner, $case['fields']['docs']);

        exportPost($this, $owner, $case['inventory'], ['format' => 'zip'])->assertStatus(422);
        exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'files' => 'photos'])->assertStatus(422);
        exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'files' => 'all'])->assertStatus(202);

        attachmentTo($case['inventory'], $case['items']['drill'], attachmentImage('front.png'), $owner, $case['fields']['photos']);

        exportPost($this, $owner, $case['inventory'], ['format' => 'zip'])->assertStatus(202);
        expect(Export::query()->count())->toBe(2);
    });

    it('looks for the files among the items of the view only', function (): void {
        Queue::fake();
        $case = zipCaseForApi();
        $owner = $case['team']['owner'];
        $name = $case['fields']['name']->ulid;

        exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'scope' => 'view', 'query' => ['q' => 'drill']])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'scope' => 'view', 'query' => ['filter' => [$name => ['empty' => 'true']]]])->assertStatus(422)->assertJsonPath('code', 'zip_without_files');
        exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'scope' => 'view', 'query' => ['q' => 'nothing like it']])->assertStatus(422);

        expect(Export::query()->count())->toBe(1);
    });

    it('does not count the photos of another inventory', function (): void {
        Queue::fake();
        $case = zipCaseForApi();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        fieldIn($other, 'photo', 'Photos');

        exportPost($this, $case['team']['owner'], $other, ['format' => 'zip'])->assertStatus(422);
    });
});

/**
 * An inventory with two photos on two items, of known sizes, for the exports that weigh something before they are written.
 *
 * @return array<string, mixed>
 */
function roomCase(): array
{
    $case = exportFixture();
    $owner = $case['team']['owner'];
    $case['drillFile'] = attachmentTo($case['inventory'], $case['items']['drill'], attachmentImage('front.png'), $owner, $case['fields']['photos']);
    $case['formulasFile'] = attachmentTo($case['inventory'], $case['items']['formulas'], attachmentImage('back.png', 'png', 60, 30), $owner, $case['fields']['photos']);
    $case['manual'] = attachmentTo($case['inventory'], $case['items']['drill'], attachmentPdf('manual.pdf'), $owner, $case['fields']['docs']);

    return $case;
}

describe('the room the exports of a person take', function (): void {
    it('is 2 GB by default, from the megabytes the configuration says', function (): void {
        expect(config('inventories.export_storage_per_user'))->toBe(2048 * 1048576);

        config(['inventories.export_storage_per_user' => (int) round(1.5 * 1048576)]);

        expect(config('inventories.export_storage_per_user'))->toBe(1572864);
    });

    it('refuses an export, 409 with its code, when the exports the person has already weigh more than the limit', function (): void {
        Queue::fake();
        config(['inventories.export_storage_per_user' => 2048]);
        $case = exportFixture();
        $owner = $case['team']['owner'];
        exportStored(exportRow($case['inventory'], $owner), 'a.csv', str_repeat('x', 1500));
        exportPost($this, $owner, $case['inventory'])->assertStatus(202);

        exportStored(exportRow($case['inventory'], $owner), 'b.csv', str_repeat('x', 1000));
        $refused = exportPost($this, $owner, $case['inventory'])->assertStatus(409);

        expect($refused->json('code'))->toBe('export_storage_full')
            ->and($refused->json('limit'))->toBe(2048)
            ->and($refused->json('message'))->toBe('Your exports take too much room: together, they cannot weigh more than 2 KB. Delete some, or wait for them to expire.')
            ->and(Export::query()->count())->toBe(3);
        Queue::assertPushed(GenerateExport::class, 1);
    });

    it('counts the exports of every inventory of the person, and nobody else\'s', function (): void {
        Queue::fake();
        config(['inventories.export_storage_per_user' => 2048]);
        $case = exportFixture();
        $second = inventoryIn($case['team']['workspace'], 'Second');
        $owner = $case['team']['owner'];
        exportStored(exportRow($second, $owner), 'other-inventory.csv', str_repeat('x', 2500));

        exportPost($this, $owner, $case['inventory'])->assertStatus(409)->assertJsonPath('code', 'export_storage_full');
        // Somebody else's exports are theirs: the editor has room.
        exportStored(exportRow($case['inventory'], $case['team']['editor']), 'editor.csv', str_repeat('x', 100));
        exportPost($this, $case['team']['editor'], $case['inventory'])->assertStatus(202);
    });

    it('is lifted by deleting an export, or when one expires, and does not count one that failed', function (): void {
        Queue::fake();
        config(['inventories.export_storage_per_user' => 2048]);
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $big = exportStored(exportRow($case['inventory'], $owner), 'big.csv', str_repeat('x', 3000));
        $failed = exportRow($case['inventory'], $owner, ['status' => ExportStatus::Failed, 'error' => ExportError::GenerationFailed->value, 'expires_at' => now()->addHour()]);

        exportPost($this, $owner, $case['inventory'])->assertStatus(409);

        $big->forceFill(['expires_at' => now()->subSecond()])->save();
        exportPost($this, $owner, $case['inventory'])->assertStatus(202);

        $big->forceFill(['expires_at' => now()->addHour()])->save();
        exportPost($this, $owner, $case['inventory'])->assertStatus(409);
        inventoryCall($this, $owner, 'DELETE', exportUri($case['inventory'], $big))->assertNoContent();
        exportPost($this, $owner, $case['inventory'])->assertStatus(202);

        expect($failed->fresh()->size)->toBeNull();
    });

    it('lets the export in that reaches the limit exactly, and refuses one more byte', function (): void {
        Queue::fake();
        $case = roomCase();
        $owner = $case['team']['owner'];
        $weight = $case['drillFile']->size;
        config(['inventories.export_storage_per_user' => $weight + 100]);
        exportStored(exportRow($case['inventory'], $owner), 'a.csv', str_repeat('x', 100));

        exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'scope' => 'view', 'query' => ['q' => 'drill']])->assertStatus(202);

        config(['inventories.export_storage_per_user' => $weight + 99]);
        // Another export is waiting to be written, a zip of the same files: the room is taken before it is written.
        exportPost($this, $owner, $case['inventory'], ['format' => 'csv'])->assertStatus(409)->assertJsonPath('code', 'export_storage_full');
    });

    it('is taken by a zip as soon as it is asked for, for the sum of the files it will hold', function (): void {
        Queue::fake();
        $case = roomCase();
        $owner = $case['team']['owner'];
        $photos = $case['drillFile']->size + $case['formulasFile']->size;

        exportPost($this, $owner, $case['inventory'], ['format' => 'zip'])->assertStatus(202)->assertJsonPath('data.size', null);
        $everything = exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'files' => 'all'])->assertStatus(202);
        $view = exportPost($this, $owner, $case['inventory'], ['format' => 'zip', 'scope' => 'view', 'query' => ['q' => 'drill']])->assertStatus(202);
        $csv = exportPost($this, $owner, $case['inventory'], ['format' => 'csv'])->assertStatus(202);

        expect(Export::query()->orderBy('id')->get()->map(fn (Export $export): ?int => $export->size)->all())->toBe([
            // The photos, the photos and the manual, the photos of the item of the view only, and nothing known for a CSV.
            $photos,
            $photos + $case['manual']->size,
            $case['drillFile']->size,
            null,
        ])->and($everything->json('data.size'))->toBeNull()
            ->and($view->json('data.size'))->toBeNull()
            ->and($csv->json('data.size'))->toBeNull();
    });

    it('refuses a zip that alone would be more than the limit', function (): void {
        Queue::fake();
        $case = roomCase();
        config(['inventories.export_storage_per_user' => $case['drillFile']->size + $case['formulasFile']->size - 1]);

        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'zip'])->assertStatus(409)->assertJsonPath('code', 'export_storage_full');
        // One photo is under the limit.
        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'zip', 'scope' => 'view', 'query' => ['q' => 'drill']])->assertStatus(202);

        expect(Export::query()->count())->toBe(1);
    });

    it('is the real size once the zip is written, which replaces the estimate, and the API gives it only then', function (): void {
        $case = roomCase();
        $owner = $case['team']['owner'];

        $created = exportPost($this, $owner, $case['inventory'], ['format' => 'zip'])->assertStatus(202);
        $export = Export::query()->sole();

        // The queue of the tests is synchronous: written by now, with its own size (the zip adds its headers to the photos).
        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->size)->toBe(strlen((string) file_get_contents(exportLocalPath($export))))
            ->and($created->json('data.size'))->toBeNull()
            ->and(inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $export))->assertOk()->json('data.size'))->toBe($export->size);
    });

    it('does not give the estimate of a zip in the list while it is not written', function (): void {
        $case = roomCase();
        $export = exportRow($case['inventory'], $case['team']['owner'], ['format' => ExportFormat::Zip, 'size' => 123456]);

        expect(inventoryCall($this, $case['team']['owner'], 'GET', exportUri($case['inventory']))->assertOk()->json('data.0.size'))->toBeNull()
            ->and($export->fresh()->size)->toBe(123456);
    });
});

describe('the limit of exports', function (): void {
    it('is 409 with its code when the person has as many as they may', function (): void {
        Queue::fake();
        config(['inventories.max_active_exports' => 3]);
        $case = exportFixture();
        $owner = $case['team']['owner'];

        foreach (range(1, 3) as $_) {
            exportPost($this, $owner, $case['inventory'])->assertStatus(202);
        }

        $refused = exportPost($this, $owner, $case['inventory'])->assertStatus(409);

        expect($refused->json('code'))->toBe('export_limit_reached')
            ->and($refused->json('limit'))->toBe(3)
            ->and($refused->json('message'))->toBe('You can have 3 exports of this inventory at a time: delete one, or wait for one to expire.')
            ->and(Export::query()->count())->toBe(3);
        Queue::assertPushed(GenerateExport::class, 3);
    });

    it('is the person\'s own, for each of their inventories, and not the others\'', function (): void {
        Queue::fake();
        config(['inventories.max_active_exports' => 2]);
        $case = exportFixture();
        $second = inventoryIn($case['team']['workspace'], 'Second');
        $owner = $case['team']['owner'];

        exportPost($this, $owner, $case['inventory'])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'])->assertStatus(202);

        // The inventory is full; another one is not: a person with a lot of exports of one inventory can still export the others.
        exportPost($this, $owner, $case['inventory'])->assertStatus(409)->assertJsonPath('code', 'export_limit_reached');
        exportPost($this, $owner, $second)->assertStatus(202);
        exportPost($this, $owner, $second)->assertStatus(202);
        exportPost($this, $owner, $second)->assertStatus(409);
        exportPost($this, $case['team']['editor'], $case['inventory'])->assertStatus(202);
    });

    it('counts every state, a failed export too, but not one that expired', function (): void {
        Queue::fake();
        config(['inventories.max_active_exports' => 3]);
        $case = exportFixture();
        $owner = $case['team']['owner'];
        exportRow($case['inventory'], $owner, ['status' => ExportStatus::Failed, 'error' => ExportError::GenerationFailed->value, 'expires_at' => now()->addHour()]);
        exportRow($case['inventory'], $owner, ['status' => ExportStatus::Running]);
        $expired = exportRow($case['inventory'], $owner, ['expires_at' => now()->subSecond()]);

        exportPost($this, $owner, $case['inventory'])->assertStatus(202);
        exportPost($this, $owner, $case['inventory'])->assertStatus(409);

        $expired->forceFill(['expires_at' => now()->addHour()])->save();
        Export::query()->where('status', ExportStatus::Failed->value)->delete();

        exportPost($this, $owner, $case['inventory'])->assertStatus(409);
    });

    it('is lifted by deleting an export', function (): void {
        Queue::fake();
        config(['inventories.max_active_exports' => 1]);
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $id = exportPost($this, $owner, $case['inventory'])->assertStatus(202)->json('data.id');

        exportPost($this, $owner, $case['inventory'])->assertStatus(409);
        inventoryCall($this, $owner, 'DELETE', exportUri($case['inventory']).'/'.$id)->assertNoContent();
        exportPost($this, $owner, $case['inventory'])->assertStatus(202);
    });

    it('is judged under the lock of the row of the person, so that two requests cannot cross it together', function (): void {
        Queue::fake();
        $case = exportFixture();

        $statements = statementsOf(fn () => exportPost($this, $case['team']['owner'], $case['inventory'])->assertStatus(202));
        $locks = array_values(array_filter($statements, fn (string $sql): bool => preg_match('/from ["`]?users["`]? .*for update/i', $sql) === 1));

        expect($locks)->not->toBe([]);
    });

    it('does not come before the checks of the request: a request with a mistake is a 422 whoever has too many', function (): void {
        Queue::fake();
        config(['inventories.max_active_exports' => 1]);
        $case = exportFixture();
        exportRow($case['inventory'], $case['team']['owner']);

        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'zip'])->assertStatus(422);
        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'docx'])->assertStatus(422);
        exportPost($this, $case['team']['owner'], $case['inventory'], ['format' => 'csv'])->assertStatus(409);
    });
});

describe('the pace of requests', function (): void {
    it('is throttled per person, with the usual answer', function (): void {
        Queue::fake();
        config(['inventories.exports_per_minute' => 3, 'inventories.max_active_exports' => 50]);
        $case = exportFixture();
        $owner = $case['team']['owner'];

        foreach (range(1, 3) as $_) {
            exportPost($this, $owner, $case['inventory'])->assertStatus(202);
        }

        $refused = exportPost($this, $owner, $case['inventory'])->assertTooManyRequests();

        // The allowance is the person's own, reading is not throttled, and a minute renews it.
        exportPost($this, $case['team']['editor'], $case['inventory'])->assertStatus(202);
        inventoryCall($this, $owner, 'GET', exportUri($case['inventory']))->assertOk();
        $this->travel(61)->seconds();
        exportPost($this, $owner, $case['inventory'])->assertStatus(202);

        expect($refused->headers->get('Retry-After'))->not->toBeNull()
            ->and(Export::query()->count())->toBe(5);
    });

    it('is 10 a minute unless the configuration says otherwise', function (): void {
        expect(config('inventories.exports_per_minute'))->toBe(10);
    });
});

describe('the list', function (): void {
    it('has the exports of the person for this inventory, the newest first', function (): void {
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $second = inventoryIn($case['team']['workspace'], 'Second');
        $old = exportRow($case['inventory'], $owner, ['created_at' => now()->subHours(3)]);
        $new = exportRow($case['inventory'], $owner, ['created_at' => now()->subHour()]);
        $newest = exportRow($case['inventory'], $owner, ['created_at' => now()]);
        exportRow($case['inventory'], $case['team']['editor']);
        exportRow($second, $owner);

        $response = inventoryCall($this, $owner, 'GET', exportUri($case['inventory']))->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe([$newest->ulid, $new->ulid, $old->ulid])
            ->and(array_keys($response->json('data.0')))->toBe(EXPORT_KEYS);
    });

    it('leaves out the exports that expired, and keeps the ones being written and the ones that failed', function (): void {
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $pending = exportRow($case['inventory'], $owner);
        $failed = exportRow($case['inventory'], $owner, ['status' => ExportStatus::Failed, 'error' => 'generation_failed', 'expires_at' => now()->addHour()]);
        $done = exportStored(exportRow($case['inventory'], $owner));
        exportRow($case['inventory'], $owner, ['expires_at' => now()->subSecond()]);

        $ids = array_column(inventoryCall($this, $owner, 'GET', exportUri($case['inventory']))->assertOk()->json('data'), 'id');

        expect($ids)->toEqualCanonicalizing([$pending->ulid, $failed->ulid, $done->ulid]);
    });

    it('has twenty exports at most', function (): void {
        $case = exportFixture();
        Export::factory()->count(23)->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id, 'disk' => ATTACHMENT_TEST_DISK]);

        expect(inventoryCall($this, $case['team']['owner'], 'GET', exportUri($case['inventory']))->assertOk()->json('data'))->toHaveCount(20);
    });

    it('is empty for a person who asked for nothing, and a 404 for one who is not a member', function (): void {
        $case = exportFixture();
        exportRow($case['inventory'], $case['team']['owner']);

        expect(inventoryCall($this, $case['team']['viewer'], 'GET', exportUri($case['inventory']))->assertOk()->json('data'))->toBe([]);
        inventoryCall($this, $case['team']['stranger'], 'GET', exportUri($case['inventory']))->assertNotFound();
    });
});

describe('one export', function (): void {
    it('is read by its author, with the address of its file once it is ready', function (): void {
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $export = exportRun(exportAsked($case['inventory'], $owner, ExportFormat::Xlsx));

        $data = inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $export))->assertOk()->json('data');

        expect(array_keys($data))->toBe(EXPORT_KEYS)
            ->and($data['id'])->toBe($export->ulid)
            ->and($data['status'])->toBe('completed')
            ->and($data['format'])->toBe('xlsx')
            ->and($data['file_name'])->toBe($export->file_name)
            ->and($data['size'])->toBe($export->size)
            ->and($data['items_count'])->toBe(3)
            ->and($data['completed_at'])->toBe($export->completed_at->toIso8601String())
            ->and($data['expires_at'])->toBe($export->expires_at->toIso8601String())
            ->and($data['error'])->toBeNull()
            // A path of the application, to be followed with the session: same origin, no query, no host.
            ->and($data['download_url'])->toBe("/api/v1/inventories/{$case['inventory']->ulid}/exports/{$export->ulid}/download");
    });

    it('has no address for its file until it is ready, and none after it expired', function (): void {
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $pending = exportRow($case['inventory'], $owner);
        $done = exportStored(exportRow($case['inventory'], $owner));

        expect(inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $pending))->json('data.download_url'))->toBeNull()
            ->and(inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $done))->json('data.download_url'))->not->toBeNull();

        $this->travel(25)->hours();

        expect(inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $done))->json('data.download_url'))->toBeNull();
    });

    it('tells why a failed export failed, in the language of the request', function (): void {
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $failed = exportRow($case['inventory'], $owner, ['status' => ExportStatus::Failed, 'error' => ExportError::InvalidQuery->value, 'expires_at' => now()->addHour()]);

        $english = inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $failed), headers: ['Accept-Language' => 'en'])->json('data');
        $french = inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $failed), headers: ['Accept-Language' => 'fr'])->json('data');

        expect($english['status'])->toBe('failed')
            ->and($english['error'])->toBe('The view this export was made on is no longer valid: a field it uses was deleted or changed.')
            ->and($french['error'])->toBe("La vue sur laquelle cet export a été demandé n'est plus valable : un champ qu'elle utilise a été supprimé ou modifié.");
    });

    it('is told as the general failure when the code is one this version does not know', function (): void {
        $case = exportFixture();
        $failed = exportRow($case['inventory'], $case['team']['owner'], ['status' => ExportStatus::Failed, 'error' => 'from_the_future', 'expires_at' => now()->addHour()]);

        expect(inventoryCall($this, $case['team']['owner'], 'GET', exportUri($case['inventory'], $failed))->json('data.error'))->toBe('The export could not be generated. Try again.');
    });

    it('is a 404 for anybody but its author, a member of the workspace of any role included', function (): void {
        $case = exportFixture();
        $export = exportStored(exportRow($case['inventory'], $case['team']['editor']));

        foreach (['owner', 'viewer', 'stranger'] as $role) {
            $as = $case['team'][$role];

            inventoryCall($this, $as, 'GET', exportUri($case['inventory'], $export))->assertNotFound();
            attachmentGet($this, $as, exportUri($case['inventory'], $export, '/download'))->assertNotFound();
            inventoryCall($this, $as, 'DELETE', exportUri($case['inventory'], $export))->assertNotFound();
        }

        // Nothing happened to it.
        expect(Export::query()->whereKey($export->id)->exists())->toBeTrue()
            ->and(storedFiles())->toBe([$export->path]);
        inventoryCall($this, $case['team']['editor'], 'GET', exportUri($case['inventory'], $export))->assertOk();
    });

    it('is a 404 under another inventory, or when it does not exist', function (): void {
        $case = exportFixture();
        $other = inventoryIn($case['team']['workspace'], 'Other');
        $export = exportRow($case['inventory'], $case['team']['owner']);

        inventoryCall($this, $case['team']['owner'], 'GET', exportUri($other, $export))->assertNotFound();
        inventoryCall($this, $case['team']['owner'], 'GET', exportUri($case['inventory']).'/01hzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
        inventoryCall($this, $case['team']['owner'], 'GET', exportUri($case['inventory']).'/not-an-id')->assertNotFound();
    });

    it('is a 404 for a member of another workspace who knows its address', function (): void {
        $case = exportFixture();
        $export = exportRow($case['inventory'], $case['team']['owner']);
        $stranger = $case['team']['stranger'];

        // The stranger belongs to a workspace, but not to this one.
        inventoryCall($this, $stranger, 'GET', exportUri($case['inventory'], $export))->assertNotFound();
        inventoryCall($this, $stranger, 'GET', exportUri($case['inventory']))->assertNotFound();
        exportPost($this, $stranger, $case['inventory'])->assertNotFound();
    });
});

describe('the download', function (): void {
    it('is the file, as an attachment, never sniffed nor cached', function (): void {
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $export = exportRun(exportAsked($case['inventory'], $owner, ExportFormat::Json));

        $response = attachmentGet($this, $owner, exportUri($case['inventory'], $export, '/download'))->assertOk();

        expect($response->streamedContent())->toBe((string) file_get_contents(exportLocalPath($export)))
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.$export->file_name.'"')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->headers->get('Content-Length'))->toBe((string) $export->size)
            // `private` and `no-store`, in the order Symfony sorts the directives.
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
            ->and($response->headers->get('Cache-Control'))->not->toContain('public');
    });

    it('carries the type of each format', function (ExportFormat $format, string $type): void {
        $case = zipCaseForApi();
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], $format));

        $response = attachmentGet($this, $case['team']['owner'], exportUri($case['inventory'], $export, '/download'))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe($type)
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
            ->and($response->headers->get('Content-Disposition'))->toContain('.'.$format->value);
    })->with([
        [ExportFormat::Xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        [ExportFormat::Ods, 'application/vnd.oasis.opendocument.spreadsheet'],
        [ExportFormat::Csv, 'text/csv; charset=UTF-8'],
        [ExportFormat::Json, 'application/json'],
        [ExportFormat::Pdf, 'application/pdf'],
        [ExportFormat::Zip, 'application/zip'],
    ]);

    it('names the file in both forms when the name has accents', function (): void {
        $case = exportFixture('Matériel électrique');
        $export = exportRun(exportAsked($case['inventory'], $case['team']['owner'], ExportFormat::Csv));

        $disposition = attachmentGet($this, $case['team']['owner'], exportUri($case['inventory'], $export, '/download'))->assertOk()->headers->get('Content-Disposition');

        expect($disposition)->toContain('filename="Materiel electrique - ')
            ->and($disposition)->toContain("filename*=utf-8''Mat%C3%A9riel%20%C3%A9lectrique%20-%20");
    });

    it('is a 409 while the export is not ready, or if it failed', function (ExportStatus $status): void {
        $case = exportFixture();
        $export = exportRow($case['inventory'], $case['team']['owner'], ['status' => $status]);

        $response = attachmentGet($this, $case['team']['owner'], exportUri($case['inventory'], $export, '/download'))->assertStatus(409);

        expect($response->json('code'))->toBe('export_not_ready')
            ->and($response->json('message'))->toBe('This export is not ready yet.');
    })->with([ExportStatus::Pending, ExportStatus::Running, ExportStatus::Failed]);

    it('is a 404 once the export expired, though it is not cleared yet', function (): void {
        $case = exportFixture();
        $export = exportStored(exportRow($case['inventory'], $case['team']['owner']));
        $this->travel(25)->hours();

        $response = attachmentGet($this, $case['team']['owner'], exportUri($case['inventory'], $export, '/download'))->assertNotFound();

        expect($response->json('code'))->toBe('export_expired');
    });

    it('is a 404 when the file is gone from its disk', function (): void {
        $case = exportFixture();
        $export = exportStored(exportRow($case['inventory'], $case['team']['owner']));
        Storage::disk(ATTACHMENT_TEST_DISK)->delete($export->path);

        $response = attachmentGet($this, $case['team']['owner'], exportUri($case['inventory'], $export, '/download'))->assertNotFound();

        expect($response->json('code'))->toBe('file_not_found');
    });

    it('is a 404 for a disk that is not configured any more', function (): void {
        $case = exportFixture();
        $export = exportStored(exportRow($case['inventory'], $case['team']['owner']));
        $export->forceFill(['disk' => 'gone'])->save();

        attachmentGet($this, $case['team']['owner'], exportUri($case['inventory'], $export, '/download'))->assertNotFound();
    });
});

describe('the deletion', function (): void {
    it('is a 204 that deletes the row now and the file with it', function (): void {
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $export = exportRun(exportAsked($case['inventory'], $owner, ExportFormat::Csv));
        $other = exportRun(exportAsked($case['inventory'], $owner, ExportFormat::Json));

        expect(storedFiles())->toBe(collect([$export->path, $other->path])->sort()->values()->all());

        inventoryCall($this, $owner, 'DELETE', exportUri($case['inventory'], $export))->assertNoContent();

        expect(Export::query()->whereKey($export->id)->exists())->toBeFalse()
            ->and(storedFiles())->toBe([$other->path]);
        inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $export))->assertNotFound();
    });

    it('purges the folder once the deletion is committed', function (): void {
        $case = exportFixture();
        $export = exportStored(exportRow($case['inventory'], $case['team']['viewer']));
        Queue::fake();

        inventoryCall($this, $case['team']['viewer'], 'DELETE', exportUri($case['inventory'], $export))->assertNoContent();

        Queue::assertPushed(PurgeStoredFiles::class, fn (PurgeStoredFiles $job): bool => $job->directories === [exportFolder($export)]);
    });

    it('deletes an export that is being written', function (): void {
        $case = exportFixture();
        $running = exportRow($case['inventory'], $case['team']['owner'], ['status' => ExportStatus::Running]);

        inventoryCall($this, $case['team']['owner'], 'DELETE', exportUri($case['inventory'], $running))->assertNoContent();

        expect(Export::query()->count())->toBe(0);
    });

    it('deletes a failed export too', function (): void {
        $case = exportFixture();
        $failed = exportRow($case['inventory'], $case['team']['owner'], ['status' => ExportStatus::Failed, 'error' => 'generation_failed']);

        inventoryCall($this, $case['team']['owner'], 'DELETE', exportUri($case['inventory'], $failed))->assertNoContent();

        expect(Export::query()->count())->toBe(0);
    });
});

describe('an export whose view stopped holding', function (): void {
    it('fails with invalid_query when the job runs after a field it used was deleted, and the API says so', function (): void {
        Queue::fake();
        $case = exportFixture();
        $owner = $case['team']['owner'];
        $quantity = $case['fields']['quantity'];

        exportPost($this, $owner, $case['inventory'], [
            'format' => 'xlsx',
            'scope' => 'view',
            'query' => ['sort' => $quantity->ulid, 'filter' => [$quantity->ulid => ['min' => '1']]],
        ])->assertStatus(202);

        app(InventoryFields::class)->delete($case['inventory'], $quantity);
        $export = Export::query()->sole();

        app(ExportGenerator::class)->run($export);

        $data = inventoryCall($this, $owner, 'GET', exportUri($case['inventory'], $export))->assertOk()->json('data');

        expect($data['status'])->toBe('failed')
            ->and($data['error'])->toContain('no longer valid')
            ->and($data['download_url'])->toBeNull()
            ->and($data['file_name'])->toBeNull()
            ->and(DB::table('exports')->where('id', $export->id)->value('error'))->toBe('invalid_query');
        attachmentGet($this, $owner, exportUri($case['inventory'], $export, '/download'))->assertStatus(409);
    });
});
