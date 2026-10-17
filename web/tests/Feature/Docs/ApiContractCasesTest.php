<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportStatus;
use App\Inventories\Imports\ImportStatus;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/contract-world.php';
require_once __DIR__.'/../../Support/exports.php';

uses(RefreshDatabase::class);

/*
 * More of the contract than one answer for each operation (ApiContractTest.php): the errors a script meets first, each with the body
 * the document gives it and a code the document lists, and the answers that hold every kind of value, every state of an export and
 * of an import.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

describe('the errors', function (): void {
    it('answers 401 to a call with no token, a token that is wrong, revoked or expired', function (): void {
        $world = contractWorld();
        $token = $world['owner']->createToken('t', ['read']);

        contractCheck($this->getJson('/api/v1/me'), 'getCurrentUser', 401);
        contractCheck($this->withToken('inv_nothing')->getJson('/api/v1/me'), 'getCurrentUser', 401);

        $this->flushHeaders();
        $token->accessToken->delete();
        app('auth')->forgetGuards();
        contractCheck($this->withToken($token->plainTextToken)->getJson('/api/v1/me'), 'getCurrentUser', 401);

        $this->flushHeaders();
        app('auth')->forgetGuards();
        $expired = $world['owner']->createToken('t', ['read'], now()->subMinute());
        contractCheck($this->withToken($expired->plainTextToken)->getJson('/api/v1/me'), 'getCurrentUser', 401);
    });

    it('answers 403 with a code the document lists to a token that lacks the ability', function (): void {
        $world = contractWorld();

        foreach ([
            ['createItem', 'read', 'POST', contractUri($world['inventory'], '/items')],
            ['createWorkspace', 'write', 'POST', '/api/v1/workspaces'],
            ['getCurrentUser', 'manage', 'GET', '/api/v1/me'],
        ] as [$operationId, $ability, $method, $uri]) {
            $response = tokenCall($this, tokenFor($world['owner'], [$ability]), $method, $uri);

            contractCheck($response, $operationId, 403);
            expect($response->json('code'))->toBe('token_ability_missing')->and(contractCodes($operationId, 403))->toContain('token_ability_missing');
        }
    });

    it('answers 403 with no code to a member whose role does not allow it, as the document says', function (): void {
        $world = contractWorld();
        $inventory = $world['inventory'];
        $workspace = $world['team']['workspace'];
        $item = $world['item'];

        foreach ([
            ['viewer', 'manage', 'updateInventory', 'PATCH', contractUri($inventory), ['name' => 'Renamed']],
            ['viewer', 'manage', 'createField', 'POST', contractUri($inventory, '/fields'), ['type' => 'text', 'name' => 'Brand']],
            ['viewer', 'write', 'createItem', 'POST', contractUri($inventory, '/items'), ['values' => []]],
            ['viewer', 'write', 'deleteItem', 'DELETE', contractUri($inventory, "/items/{$item->ulid}"), []],
            ['viewer', 'read', 'listImports', 'GET', contractUri($inventory, '/imports'), []],
            ['viewer', 'write', 'createImport', 'POST', contractUri($inventory, '/imports'), []],
            ['editor', 'manage', 'updateWorkspace', 'PATCH', "/api/v1/workspaces/{$workspace->ulid}", ['name' => 'Renamed']],
            ['editor', 'manage', 'deleteWorkspace', 'DELETE', "/api/v1/workspaces/{$workspace->ulid}", []],
            ['editor', 'manage', 'deleteInventory', 'DELETE', contractUri($inventory), []],
        ] as [$role, $ability, $operationId, $method, $uri, $data]) {
            $response = tokenCall($this, tokenFor($world['team'][$role], [$ability]), $method, $uri, $data);

            contractCheck($response, $operationId, 403);
            expect($response->json())->not->toHaveKey('code', "{$role} {$operationId}");
        }
    });

    it('lets a viewer do what the document says viewers may: read, and ask for an export', function (): void {
        Queue::fake();
        $world = contractWorld();
        $viewer = $world['team']['viewer'];

        contractCheck(tokenCall($this, tokenFor($viewer, ['read']), 'GET', contractUri($world['inventory'], '/items')), 'listItems', 200);
        contractCheck(tokenCall($this, tokenFor($viewer, ['write']), 'POST', contractUri($world['inventory'], '/exports'), ['format' => 'csv']), 'createExport', 202);
    });

    it('answers 404 to what does not exist or belongs to a workspace the owner of the token is not in', function (): void {
        $world = contractWorld();
        $stranger = $world['team']['stranger'];

        $notYours = contractCall($this, $stranger, 'getInventory', 'GET', contractUri($world['inventory']));
        contractCheck($notYours, 'getInventory', 404);

        contractCheck(contractCall($this, $world['owner'], 'getItem', 'GET', contractUri($world['inventory'], '/items/01j0000000000000000000000x')), 'getItem', 404);
        contractCheck(contractCall($this, $stranger, 'listExports', 'GET', contractUri($world['inventory'], '/exports')), 'listExports', 404);
    });

    it('answers 404 with a code the document lists to a template that does not exist', function (): void {
        templateFixtures();

        $response = contractCall($this, null, 'getTemplate', 'GET', '/api/v1/templates/nothing-here');

        contractCheck($response, 'getTemplate', 404);
        expect(contractCodes('getTemplate', 404))->toContain((string) $response->json('code'));
    });

    it('answers 422 with the faults of the body under their keys', function (): void {
        $world = contractWorld();

        $response = contractCall($this, $world['owner'], 'createWorkspace', 'POST', '/api/v1/workspaces', ['name' => ''], valid: false);
        contractCheck($response, 'createWorkspace', 422);
        expect($response->json('errors'))->toHaveKey('name');

        $item = contractCall($this, $world['owner'], 'createItem', 'POST', contractUri($world['inventory'], '/items'), ['values' => [$world['price']->ulid => 'not a number']]);
        contractCheck($item, 'createItem', 422);
        expect($item->json('errors'))->toHaveKey('values.'.$world['price']->ulid);

        contractCheck(contractCall($this, $world['owner'], 'listItems', 'GET', contractUri($world['inventory'], '/items?page=0')), 'listItems', 422);
    });

    it('answers 409 with a code the document lists and the quota that was reached', function (): void {
        config(['inventories.max_items' => 1]);
        $world = contractWorld();

        $response = contractCall($this, $world['owner'], 'createItem', 'POST', contractUri($world['inventory'], '/items'), ['values' => (object) []]);

        contractCheck($response, 'createItem', 409);
        expect(contractCodes('createItem', 409))->toContain((string) $response->json('code'))->and($response->json('limit'))->toBe(1);
    });

    it('answers 429 in JSON with the headers the document lists and a code it lists', function (): void {
        config(['inventories.api_token_requests_per_minute' => 1]);
        $world = contractWorld();
        $token = tokenFor($world['owner'], ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        $response = tokenCall($this, $token, 'GET', '/api/v1/me');

        contractCheck($response, 'getCurrentUser', 429);

        foreach (contractHeaders('getCurrentUser', 429) as $header) {
            expect($response->headers->has($header))->toBeTrue("the header {$header}")
                ->and($response->headers->get($header))->toMatch('/^\d+$/', $header);
        }

        expect($response->json('code'))->toBe('too_many_requests')->and(contractCodes('getCurrentUser', 429))->toContain('too_many_requests');
    });

    it('answers 429 to the public catalogue too, which has a pace of its own', function (): void {
        templateFixtures();
        config(['inventories.templates_per_minute' => 1]);

        contractCall($this, null, 'listTemplates', 'GET', '/api/v1/templates')->assertOk();

        contractCheck(contractCall($this, null, 'listTemplates', 'GET', '/api/v1/templates'), 'listTemplates', 429);
    });
});

describe('the answers that hold every kind of value', function (): void {
    it('fit the schemas: the fields of every type, and the items that fill them, leave them empty or hold what a spreadsheet would run', function (): void {
        $made = exportFixture();
        $owner = $made['team']['owner'];
        $inventory = $made['inventory'];

        $details = contractCall($this, $owner, 'getInventory', 'GET', contractUri($inventory));
        contractCheck($details, 'getInventory', 200);
        expect(count($details->json('data.fields')))->toBeGreaterThanOrEqual(18);

        $list = contractCall($this, $owner, 'listItems', 'GET', contractUri($inventory, '/items'));
        contractCheck($list, 'listItems', 200);
        expect($list->json('data'))->toHaveCount(3);

        foreach ($made['items'] as $item) {
            contractCheck(contractCall($this, $owner, 'getItem', 'GET', contractUri($inventory, "/items/{$item->ulid}")), 'getItem', 200);
            contractCheck(contractCall($this, $owner, 'listItemRevisions', 'GET', contractUri($inventory, "/items/{$item->ulid}/revisions")), 'listItemRevisions', 200);
        }
    });

    it('fit the schemas when the items are changed and a file is attached to one', function (): void {
        $world = contractWorld();
        $item = $world['item'];
        contractAttachment($world);

        contractCall($this, $world['owner'], 'updateItem', 'PATCH', contractUri($world['inventory'], "/items/{$item->ulid}"), ['values' => [$world['name']->ulid => null, $world['price']->ulid => '1']])->assertOk();

        contractCheck(contractCall($this, $world['owner'], 'getItem', 'GET', contractUri($world['inventory'], "/items/{$item->ulid}")), 'getItem', 200);
        contractCheck(contractCall($this, $world['owner'], 'listItemRevisions', 'GET', contractUri($world['inventory'], "/items/{$item->ulid}/revisions")), 'listItemRevisions', 200);
        contractCheck(contractCall($this, $world['owner'], 'listItems', 'GET', contractUri($world['inventory'], '/items')), 'listItems', 200);
    });

    it('fit the schemas for an export in every state', function (): void {
        $world = contractWorld();

        foreach (ExportStatus::cases() as $status) {
            exportRow($world['inventory'], $world['owner'], ['status' => $status] + ($status === ExportStatus::Failed ? ['error' => 'export_storage_full'] : []));
        }

        contractExport($world);

        $response = contractCall($this, $world['owner'], 'listExports', 'GET', contractUri($world['inventory'], '/exports'));
        contractCheck($response, 'listExports', 200);

        expect(collect($response->json('data'))->pluck('status')->unique()->sort()->values()->all())->toBe(['completed', 'failed', 'pending', 'running']);
    });

    it('fit the schemas for an import that was sent, one that ran with rows it refused, and one that failed', function (): void {
        Queue::fake();
        $world = contractWorld();
        $case = ['team' => $world['team'], 'inventory' => $world['inventory']];
        $path = importFile(importCsv([['Name', 'Price'], ['Drill', '30'], ['Saw', 'abc'], ['', '']]));

        $sent = importAsked($this, $case, $path, settings: ['on_error' => 'skip']);
        $done = importDone($sent);

        foreach ([$sent, importWithFile($world['inventory'], $world['owner'], ['status' => ImportStatus::Failed, 'errors' => []])] as $import) {
            $response = contractCall($this, $world['owner'], 'getImport', 'GET', contractUri($world['inventory'], "/imports/{$import->ulid}"));
            contractCheck($response, 'getImport', 200);
        }

        $list = contractCall($this, $world['owner'], 'listImports', 'GET', contractUri($world['inventory'], '/imports'));
        contractCheck($list, 'listImports', 200);

        expect($done->status)->toBe(ImportStatus::Completed)->and($done->rows_failed)->toBe(1)->and($done->errors)->toHaveCount(1)
            ->and(Import::query()->count())->toBe(2);
    });

    it('fit the schemas for the catalogue of templates and a template with its examples', function (): void {
        templateFixtures();

        foreach (['sample-home', 'sample-records', 'sample-shop', 'sample-tools'] as $slug) {
            contractCheck(contractCall($this, null, 'getTemplate', 'GET', "/api/v1/templates/{$slug}"), 'getTemplate', 200);
        }

        contractCheck(contractCall($this, null, 'listTemplates', 'GET', '/api/v1/templates', [], ['Accept-Language' => 'fr']), 'listTemplates', 200);
    });
});
