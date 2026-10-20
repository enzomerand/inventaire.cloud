<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportStatus;
use App\Inventories\Imports\ImportStatus;
use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SignerStatus;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionStatus;
use App\Models\Import;
use App\Models\InventoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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
        $version = InventoryVersion::factory()->create(['inventory_id' => $inventory->id]);

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
            ['viewer', 'write', 'createVersion', 'POST', contractUri($inventory, '/versions'), []],
            ['editor', 'write', 'deleteVersion', 'DELETE', contractUri($inventory, "/versions/{$version->ulid}"), []],
            ['viewer', 'write', 'cancelVersionSignature', 'POST', contractUri($inventory, "/versions/{$version->ulid}/signature/cancel"), []],
            ['viewer', 'write', 'retryVersionSignature', 'POST', contractUri($inventory, "/versions/{$version->ulid}/signature/retry"), []],
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

        $version = contractVersion($world);
        contractCheck(tokenCall($this, tokenFor($viewer, ['read']), 'GET', contractUri($world['inventory'], '/versions')), 'listVersions', 200);
        contractCheck(tokenCall($this, tokenFor($viewer, ['read']), 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}")), 'getVersion', 200);
    });

    it('answers 404 to what does not exist or belongs to a workspace the owner of the token is not in', function (): void {
        $world = contractWorld();
        $stranger = $world['team']['stranger'];

        $notYours = contractCall($this, $stranger, 'getInventory', 'GET', contractUri($world['inventory']));
        contractCheck($notYours, 'getInventory', 404);

        contractCheck(contractCall($this, $world['owner'], 'getItem', 'GET', contractUri($world['inventory'], '/items/01j0000000000000000000000x')), 'getItem', 404);
        contractCheck(contractCall($this, $stranger, 'listExports', 'GET', contractUri($world['inventory'], '/exports')), 'listExports', 404);
        contractCheck(contractCall($this, $stranger, 'listVersions', 'GET', contractUri($world['inventory'], '/versions')), 'listVersions', 404);

        $version = InventoryVersion::factory()->create(['inventory_id' => $world['inventory']->id]);
        $other = inventoryIn($world['team']['workspace'], 'Other');
        contractCheck(contractCall($this, $world['owner'], 'getVersion', 'GET', contractUri($other, "/versions/{$version->ulid}")), 'getVersion', 404);
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

describe('the errors of the official versions', function (): void {
    it('answers 409 with the codes the document lists, and the quota that was reached', function (): void {
        $world = contractWorld();
        $uri = contractUri($world['inventory'], '/versions');

        config(['services.documenso.url' => null, 'services.documenso.api_key' => null]);
        $unavailable = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri, ['signers' => versionSigners(1)]);
        contractCheck($unavailable, 'createVersion', 409);

        config(['inventories.storage_per_workspace' => 0]);
        $full = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri);
        contractCheck($full, 'createVersion', 409);

        config(['inventories.storage_per_workspace' => 1_000_000_000, 'inventories.max_versions_per_inventory' => 1]);
        contractVersion($world);
        $limit = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri);
        contractCheck($limit, 'createVersion', 409);

        expect([$unavailable->json('code'), $full->json('code'), $limit->json('code')])->toBe(['signature_unavailable', 'storage_limit_reached', 'version_limit_reached'])
            ->and(contractCodes('createVersion', 409))->toContain('signature_unavailable', 'storage_limit_reached', 'version_limit_reached')
            ->and($limit->json('limit'))->toBe(1)
            ->and($full->json('limit'))->toBe(0);
    });

    it('answers 409 to the download of a file that is not ready, with a code the document lists', function (): void {
        $world = contractWorld();
        $version = InventoryVersion::factory()->create(['inventory_id' => $world['inventory']->id, 'status' => VersionStatus::Running]);

        foreach (['downloadVersionPdf' => 'pdf', 'downloadVersionSnapshot' => 'snapshot'] as $operationId => $file) {
            $response = contractCall($this, $world['owner'], $operationId, 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}/{$file}"));

            contractCheck($response, $operationId, 409);
            expect($response->json('code'))->toBe('version_not_ready')->and(contractCodes($operationId, 409))->toContain('version_not_ready');
        }
    });

    it('answers 404 with a code the document lists to a file that is gone from the storage', function (): void {
        $world = contractWorld();
        $version = contractVersion($world);
        Storage::disk(ATTACHMENT_TEST_DISK)->deleteDirectory((string) $version->directory());

        foreach (['downloadVersionPdf' => 'pdf', 'downloadVersionSnapshot' => 'snapshot'] as $operationId => $file) {
            $response = contractCall($this, $world['owner'], $operationId, 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}/{$file}"));

            contractCheck($response, $operationId, 404);
            expect($response->json('code'))->toBe('file_not_found')->and(contractCodes($operationId, 404))->toContain('file_not_found');
        }
    });

    it('answers 422 with the faults of the body under their keys, and with a code for an inventory a PDF cannot hold', function (): void {
        $world = contractWorld();
        $uri = contractUri($world['inventory'], '/versions');
        versionDocumenso();

        $signers = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri, ['signers' => [['name' => 'Ada', 'email' => 'not an address']]], valid: true);
        contractCheck($signers, 'createVersion', 422);
        expect($signers->json('errors'))->toHaveKey('signers.0.email');

        $label = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri, ['label' => str_repeat('x', 121)], valid: false);
        contractCheck($label, 'createVersion', 422);
        expect($label->json('errors'))->toHaveKey('label');

        config(['inventories.pdf_max_items' => 1]);
        itemIn($world['inventory'], [], $world['owner']);
        $large = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri);
        contractCheck($large, 'createVersion', 422);
        expect($large->json('code'))->toBe('pdf_too_large')->and(contractCodes('createVersion', 422))->toContain('pdf_too_large')
            ->and($large->json('limit'))->toBeInt();
    });

    it('answers 429 in JSON to a person who freezes too often, with the headers the document lists', function (): void {
        Queue::fake();
        config(['inventories.versions_per_hour' => 1]);
        $world = contractWorld();
        $uri = contractUri($world['inventory'], '/versions');

        contractCheck(contractCall($this, $world['owner'], 'createVersion', 'POST', $uri), 'createVersion', 202);
        $response = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri);

        contractCheck($response, 'createVersion', 429);

        foreach (contractHeaders('createVersion', 429) as $header) {
            expect($response->headers->has($header))->toBeTrue("the header {$header}");
        }

        expect($response->json('code'))->toBe('too_many_requests');
    });
});

describe('the daily limit of the requests for signatures', function (): void {
    it('answers 429 in JSON to a workspace that asks for signatures too often, on both operations that ask, with the code and the headers the document lists', function (): void {
        Queue::fake();
        config(['inventories.signature_requests_per_workspace_per_day' => 1]);
        $world = contractWorld();
        $uri = contractUri($world['inventory'], '/versions');
        versionDocumenso();
        $signers = ['signers' => versionSigners(1)];

        contractCheck(contractCall($this, $world['owner'], 'createVersion', 'POST', $uri, $signers), 'createVersion', 202);
        $created = contractCall($this, $world['owner'], 'createVersion', 'POST', $uri, $signers);
        contractCheck($created, 'createVersion', 429);

        $failed = versionFreeze($world['inventory'], $world['owner']);
        $failed->forceFill(['status' => VersionStatus::Completed, 'signature_status' => SignatureStatus::Failed, 'pdf_path' => 'x/official.pdf', 'pdf_size' => 10, 'pdf_sha256' => str_repeat('a', 64), 'pdf_pages' => 1])->save();
        $retried = contractCall($this, $world['owner'], 'retryVersionSignature', 'POST', contractUri($world['inventory'], "/versions/{$failed->ulid}/signature/retry"));
        contractCheck($retried, 'retryVersionSignature', 429);

        foreach (['createVersion' => $created, 'retryVersionSignature' => $retried] as $operation => $response) {
            foreach (contractHeaders($operation, 429) as $header) {
                expect($response->headers->has($header))->toBeTrue("the header {$header} of {$operation}");
            }

            expect($response->json('code'))->toBe('too_many_requests')
                ->and(contractCodes($operation, 429))->toContain('too_many_requests')
                ->and((int) $response->headers->get('Retry-After'))->toBeBetween(86300, 86400);
        }
    });

    it('is told in the document: the descriptions of both operations name the limit of the workspace', function (): void {
        $document = (string) file_get_contents(resource_path('openapi/v1.yaml'));

        expect(substr_count($document, 'the workspace has already made as many'))->toBe(2)
            ->and($document)->toContain('A workspace can ask for signatures 20 times a day by default')->toContain('the workspace can make 20 a day by default');
    });
});

describe('the errors of the requests for signatures', function (): void {
    it('answers 409 to the download of a signed PDF that is not ready, with a code the document lists', function (): void {
        $world = contractWorld();
        $version = contractVersion($world, versionSigners(1));

        $response = contractCall($this, $world['owner'], 'downloadVersionSignedPdf', 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}/signed-pdf"));

        contractCheck($response, 'downloadVersionSignedPdf', 409);
        expect($response->json('code'))->toBe('signed_pdf_not_ready')->and(contractCodes('downloadVersionSignedPdf', 409))->toContain('signed_pdf_not_ready');
    });

    it('answers 404 with a code the document lists to a signed PDF that is gone from the storage', function (): void {
        $world = contractWorld();
        $version = contractSigned($world);
        Storage::disk(ATTACHMENT_TEST_DISK)->delete((string) $version->signed_pdf_path);

        $response = contractCall($this, $world['owner'], 'downloadVersionSignedPdf', 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}/signed-pdf"));

        contractCheck($response, 'downloadVersionSignedPdf', 404);
        expect($response->json('code'))->toBe('file_not_found')->and(contractCodes('downloadVersionSignedPdf', 404))->toContain('file_not_found');
    });

    it('answers 409 to the cancellation of a request that does not await its signers, and 503 when Documenso cannot be reached', function (): void {
        $fake = versionDocumenso();
        $world = contractWorld();
        $uri = fn (InventoryVersion $version): string => contractUri($world['inventory'], "/versions/{$version->ulid}/signature/cancel");

        $none = contractVersion($world);
        $refused = contractCall($this, $world['owner'], 'cancelVersionSignature', 'POST', $uri($none));
        contractCheck($refused, 'cancelVersionSignature', 409);

        $awaiting = contractVersion($world, versionSigners(1));
        $fake->failNext('POST /envelope/cancel', null);
        $unavailable = contractCall($this, $world['owner'], 'cancelVersionSignature', 'POST', $uri($awaiting));
        contractCheck($unavailable, 'cancelVersionSignature', 503);

        // Documenso says the envelope is not pending any more.
        Queue::fake();
        $fake->failNext('POST /envelope/cancel', 400);
        $notPending = contractCall($this, $world['owner'], 'cancelVersionSignature', 'POST', $uri($awaiting));
        contractCheck($notPending, 'cancelVersionSignature', 409);

        expect([$refused->json('code'), $unavailable->json('code'), $notPending->json('code')])->toBe(['signature_not_pending', 'documenso_unavailable', 'signature_not_pending'])
            ->and(contractCodes('cancelVersionSignature', 409))->toContain('signature_not_pending')
            ->and(contractCodes('cancelVersionSignature', 503))->toContain('documenso_unavailable');
    });

    it('answers 409 to the sending again of a request that did not fail, or on an instance with no Documenso', function (): void {
        $world = contractWorld();
        $version = contractVersion($world, versionSigners(1));
        $uri = contractUri($world['inventory'], "/versions/{$version->ulid}/signature/retry");

        $notFailed = contractCall($this, $world['owner'], 'retryVersionSignature', 'POST', $uri);
        contractCheck($notFailed, 'retryVersionSignature', 409);

        $version->forceFill(['signature_status' => SignatureStatus::Failed, 'signature_error' => SignatureError::Stale])->save();
        config(['services.documenso.url' => null, 'services.documenso.api_key' => null]);
        $unavailable = contractCall($this, $world['owner'], 'retryVersionSignature', 'POST', $uri);
        contractCheck($unavailable, 'retryVersionSignature', 409);

        expect([$notFailed->json('code'), $unavailable->json('code')])->toBe(['signature_not_failed', 'signature_unavailable'])
            ->and(contractCodes('retryVersionSignature', 409))->toContain('signature_not_failed', 'signature_unavailable');
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

    it('fit the schemas for a version in every state, with and without signers', function (): void {
        $world = contractWorld();
        $inventory = $world['inventory'];
        $number = 0;
        $row = function (array $attributes) use ($inventory, &$number): InventoryVersion {
            return InventoryVersion::factory()->create(['inventory_id' => $inventory->id, 'number' => ++$number] + $attributes);
        };
        $signers = [
            ['name' => 'Ada', 'email' => 'ada@example.test', 'status' => SignerStatus::Signed->value, 'signed_at' => '2026-10-15T12:30:00.000Z', 'rejection_reason' => null, 'recipient_id' => 7],
            ['name' => 'Grace', 'email' => 'grace@example.test', 'status' => SignerStatus::Rejected->value, 'signed_at' => null, 'rejection_reason' => 'Not mine', 'recipient_id' => 8],
            ['name' => 'Linus', 'email' => 'linus@example.test', 'status' => SignerStatus::Pending->value, 'signed_at' => null, 'rejection_reason' => null, 'recipient_id' => null],
        ];

        foreach ([VersionStatus::Pending, VersionStatus::Running] as $status) {
            $row(['status' => $status]);
        }

        foreach (VersionError::cases() as $error) {
            $row(['status' => VersionStatus::Failed, 'error' => $error, 'label' => "Failed: {$error->value}"]);
        }

        $row(['status' => VersionStatus::Failed, 'error' => VersionError::StorageFailed, 'signature_status' => SignatureStatus::Requested, 'signers' => $signers]);

        foreach (SignatureStatus::cases() as $status) {
            $row([
                'status' => VersionStatus::Completed,
                'pdf_path' => 'x/official.pdf', 'pdf_size' => 10, 'pdf_sha256' => str_repeat('a', 64), 'pdf_pages' => 2,
                'signature_status' => $status,
                'signature_error' => $status === SignatureStatus::Failed ? SignatureError::DocumensoRefused : null,
                'signature_requested_at' => now(),
                'signers' => $signers,
            ]);
        }

        contractVersion($world);

        $response = contractCall($this, $world['owner'], 'listVersions', 'GET', contractUri($inventory, '/versions'));
        contractCheck($response, 'listVersions', 200);

        expect($response->json('data'))->toHaveCount(2 + 4 + 1 + 7 + 1)
            ->and(collect($response->json('data'))->pluck('status')->unique()->sort()->values()->all())->toBe(['completed', 'failed', 'pending', 'running']);

        foreach ($response->json('data') as $version) {
            contractCheck(contractCall($this, $world['owner'], 'getVersion', 'GET', contractUri($inventory, "/versions/{$version['id']}")), 'getVersion', 200);
        }
    });

    it('fit the schemas for the catalogue of templates and a template with its examples', function (): void {
        templateFixtures();

        foreach (['sample-home', 'sample-records', 'sample-shop', 'sample-tools'] as $slug) {
            contractCheck(contractCall($this, null, 'getTemplate', 'GET', "/api/v1/templates/{$slug}"), 'getTemplate', 200);
        }

        contractCheck(contractCall($this, null, 'listTemplates', 'GET', '/api/v1/templates', [], ['Accept-Language' => 'fr']), 'listTemplates', 200);
    });
});

describe('the errors of the members and the invitations', function (): void {
    it('answers 403 with the code not_owner, which the document lists, to a member who is not the owner', function (): void {
        $world = contractWorld();
        $team = $world['team'];
        $workspace = $team['workspace'];
        $invitation = contractInvitation($workspace, 'guest@example.test', $world['owner']);
        $uri = "/api/v1/workspaces/{$workspace->ulid}";

        foreach ([
            ['updateMemberRole', 'PATCH', contractMemberUri($workspace, $team['viewer']), ['role' => 'editor']],
            ['removeMember', 'DELETE', contractMemberUri($workspace, $team['viewer']), []],
            ['listInvitations', 'GET', "{$uri}/invitations", []],
            ['createInvitation', 'POST', "{$uri}/invitations", ['email' => 'other@example.test', 'role' => 'viewer']],
            ['resendInvitation', 'POST', "{$uri}/invitations/{$invitation->ulid}/resend", []],
            ['cancelInvitation', 'DELETE', "{$uri}/invitations/{$invitation->ulid}", []],
        ] as [$operationId, $method, $path, $data]) {
            $response = contractCall($this, $team['editor'], $operationId, $method, $path, $data);

            contractCheck($response, $operationId, 403);
            expect($response->json('code'))->toBe('not_owner')->and(contractCodes($operationId, 403))->toContain('not_owner', 'token_ability_missing');
        }
    });

    it('answers 403 with the code token_ability_missing to a token that lacks the ability, on the operations that read and the ones that manage', function (): void {
        $world = contractWorld();
        $workspace = $world['team']['workspace'];
        $guest = $world['team']['stranger'];
        $invitation = contractInvitation($workspace, $guest->email, $world['owner']);

        foreach ([
            ['listMembers', 'manage', 'GET', contractMemberUri($workspace)],
            ['listInvitations', 'write', 'GET', "/api/v1/workspaces/{$workspace->ulid}/invitations"],
            ['listMyInvitations', 'manage', 'GET', '/api/v1/me/invitations'],
            ['updateMemberRole', 'read', 'PATCH', contractMemberUri($workspace, $world['team']['editor'])],
            ['createInvitation', 'write', 'POST', "/api/v1/workspaces/{$workspace->ulid}/invitations"],
            ['acceptInvitation', 'read', 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept"],
            ['declineInvitation', 'write', 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}"],
        ] as [$operationId, $ability, $method, $uri]) {
            $response = tokenCall($this, tokenFor($world['owner'], [$ability]), $method, $uri);

            contractCheck($response, $operationId, 403);
            expect($response->json('code'))->toBe('token_ability_missing')->and(contractCodes($operationId, 403))->toContain('token_ability_missing');
        }
    });

    it('answers 404 to what is not there for the caller: a workspace, a member or an invitation that is not theirs', function (): void {
        $world = contractWorld();
        $workspace = $world['team']['workspace'];
        $stranger = $world['team']['stranger'];
        $invitation = contractInvitation($workspace, 'guest@example.test', $world['owner']);

        contractCheck(contractCall($this, $stranger, 'listMembers', 'GET', contractMemberUri($workspace)), 'listMembers', 404);
        contractCheck(contractCall($this, $stranger, 'createInvitation', 'POST', "/api/v1/workspaces/{$workspace->ulid}/invitations", ['email' => 'x@example.test', 'role' => 'viewer']), 'createInvitation', 404);
        contractCheck(contractCall($this, $world['owner'], 'removeMember', 'DELETE', contractMemberUri($workspace, $stranger)), 'removeMember', 404);
        contractCheck(contractCall($this, $world['owner'], 'cancelInvitation', 'DELETE', "/api/v1/workspaces/{$workspace->ulid}/invitations/01j0000000000000000000000x"), 'cancelInvitation', 404);

        // An invitation that is not addressed to the person is no business of theirs, whoever they are.
        foreach ([['acceptInvitation', 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept"], ['declineInvitation', 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}"]] as [$operationId, $method, $uri]) {
            contractCheck(contractCall($this, $stranger, $operationId, $method, $uri), $operationId, 404);
            contractCheck(contractCall($this, $world['owner'], $operationId, $method, $uri), $operationId, 404);
        }
    });

    it('answers 409 with the codes the document lists, and the quota that was reached', function (): void {
        $world = contractWorld();
        $team = $world['team'];
        $workspace = $team['workspace'];
        $uri = "/api/v1/workspaces/{$workspace->ulid}";

        $cases = [
            ['updateMemberRole', 'PATCH', contractMemberUri($workspace, $team['owner']), ['role' => 'viewer'], 'cannot_change_owner_role'],
            ['removeMember', 'DELETE', contractMemberUri($workspace, $team['owner']), [], 'owner_cannot_leave'],
            ['createInvitation', 'POST', "{$uri}/invitations", ['email' => $team['viewer']->email, 'role' => 'viewer'], 'already_member'],
        ];

        contractInvitation($workspace, 'waiting@example.test', $world['owner']);
        $cases[] = ['createInvitation', 'POST', "{$uri}/invitations", ['email' => 'waiting@example.test', 'role' => 'viewer'], 'already_invited'];

        foreach ($cases as [$operationId, $method, $path, $data, $code]) {
            $response = contractCall($this, $team['owner'], $operationId, $method, $path, $data);

            contractCheck($response, $operationId, 409);
            expect($response->json('code'))->toBe($code)->and(contractCodes($operationId, 409))->toContain($code);
        }
    });

    it('answers 409 with the limit when the workspace is full or the person belongs to as many workspaces as they may', function (): void {
        $world = contractWorld();
        $workspace = $world['team']['workspace'];
        $guest = $world['team']['stranger'];
        $invitation = contractInvitation($workspace, $guest->email, $world['owner']);
        config(['workspaces.max_members' => 4]);

        $full = contractCall($this, $world['owner'], 'createInvitation', 'POST', "/api/v1/workspaces/{$workspace->ulid}/invitations", ['email' => 'second@example.test', 'role' => 'viewer']);
        contractCheck($full, 'createInvitation', 409);

        config(['workspaces.max_members' => 3]);
        $accept = contractCall($this, $guest, 'acceptInvitation', 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept");
        contractCheck($accept, 'acceptInvitation', 409);

        config(['workspaces.max_members' => 50, 'workspaces.max_per_user' => 1]);
        $joined = contractCall($this, $guest, 'acceptInvitation', 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept");
        contractCheck($joined, 'acceptInvitation', 409);

        expect([$full->json('code'), $accept->json('code'), $joined->json('code')])->toBe(['member_limit_reached', 'member_limit_reached', 'membership_limit_reached'])
            ->and([$full->json('limit'), $accept->json('limit'), $joined->json('limit')])->toBe([4, 3, 1])
            ->and(contractCodes('acceptInvitation', 409))->toContain('member_limit_reached', 'membership_limit_reached')
            ->and(contractCodes('createInvitation', 409))->toContain('member_limit_reached');
    });

    it('answers 422 with the faults of the body under their keys', function (): void {
        $world = contractWorld();
        $workspace = $world['team']['workspace'];

        $invalid = contractCall($this, $world['owner'], 'createInvitation', 'POST', "/api/v1/workspaces/{$workspace->ulid}/invitations", ['email' => 'nobody', 'role' => 'owner'], valid: false);
        contractCheck($invalid, 'createInvitation', 422);
        expect($invalid->json('errors'))->toHaveKeys(['email', 'role']);

        $role = contractCall($this, $world['owner'], 'updateMemberRole', 'PATCH', contractMemberUri($workspace, $world['team']['editor']), ['role' => 'owner'], valid: false);
        contractCheck($role, 'updateMemberRole', 422);
        expect($role->json('errors'))->toHaveKey('role');
    });

    it('answers 429 to a resend that comes too soon, with the code the document lists, retry_after and the Retry-After header', function (): void {
        $world = contractWorld();
        $workspace = $world['team']['workspace'];
        $invitation = contractInvitation($workspace, 'guest@example.test', $world['owner'], ['updated_at' => now()->subMinutes(2)]);

        $response = contractCall($this, $world['owner'], 'resendInvitation', 'POST', "/api/v1/workspaces/{$workspace->ulid}/invitations/{$invitation->ulid}/resend");

        contractCheck($response, 'resendInvitation', 429);
        expect($response->json('code'))->toBe('resend_too_soon')
            ->and(contractCodes('resendInvitation', 429))->toContain('resend_too_soon', 'too_many_requests')
            ->and(contractHeaders('resendInvitation', 429))->toContain('Retry-After')
            ->and($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(480)
            ->and($response->headers->get('Retry-After'))->toBe((string) $response->json('retry_after'));
    });

    it('answers 429 to the invitations of an owner beyond their pace, with the code the document lists', function (): void {
        config(['workspaces.invitations_per_hour' => 1]);
        $world = contractWorld();
        $uri = "/api/v1/workspaces/{$world['team']['workspace']->ulid}/invitations";

        contractCall($this, $world['owner'], 'createInvitation', 'POST', $uri, ['email' => 'one@example.test', 'role' => 'viewer'])->assertCreated();
        $response = contractCall($this, $world['owner'], 'createInvitation', 'POST', $uri, ['email' => 'two@example.test', 'role' => 'viewer']);

        contractCheck($response, 'createInvitation', 429);
        expect($response->json('code'))->toBe('too_many_requests')->and(contractCodes('createInvitation', 429))->toContain('too_many_requests');

        foreach (contractHeaders('createInvitation', 429) as $header) {
            expect($response->headers->has($header))->toBeTrue("the header {$header}");
        }
    });
});
