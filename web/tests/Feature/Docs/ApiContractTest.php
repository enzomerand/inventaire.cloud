<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportFormat;
use App\Inventories\Versions\SignatureError;
use App\Inventories\Versions\SignatureStatus;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/contract-world.php';

uses(RefreshDatabase::class);

/*
 * The contract: for every operation of the OpenAPI document (resources/openapi/v1.yaml), a real call made with an API token, whose
 * answer has the documented status and, for a JSON body, fits the schema the document gives for it. The documentation is written by
 * hand, so this is what keeps it true: a change to the API that the document does not follow fails here.
 *
 * The scenarios are keyed by operationId, and a test makes sure they are exactly the operations of the document.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

/**
 * The scenarios, by operationId: each makes a real call, then holds its answer to the document.
 *
 * @return array<string, Closure(object): void>
 */
function contractScenarios(): array
{
    return [
        'getCurrentUser' => function (object $test): void {
            contractCheck(contractCall($test, contractWorld()['owner'], 'getCurrentUser', 'GET', '/api/v1/me'), 'getCurrentUser', 200);
        },

        'listFieldTypes' => function (object $test): void {
            contractCheck(contractCall($test, contractWorld()['owner'], 'listFieldTypes', 'GET', '/api/v1/field-types'), 'listFieldTypes', 200);
        },

        'listWorkspaces' => function (object $test): void {
            contractCheck(contractCall($test, contractWorld()['owner'], 'listWorkspaces', 'GET', '/api/v1/workspaces'), 'listWorkspaces', 200);
        },

        'createWorkspace' => function (object $test): void {
            contractCheck(contractCall($test, contractWorld()['owner'], 'createWorkspace', 'POST', '/api/v1/workspaces', ['name' => 'Garage']), 'createWorkspace', 201);
        },

        'updateWorkspace' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'updateWorkspace', 'PATCH', "/api/v1/workspaces/{$world['team']['workspace']->ulid}", ['name' => 'Renamed']), 'updateWorkspace', 200);
        },

        'deleteWorkspace' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'deleteWorkspace', 'DELETE', "/api/v1/workspaces/{$world['team']['workspace']->ulid}"), 'deleteWorkspace', 204);
        },

        'listMembers' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'listMembers', 'GET', contractMemberUri($world['team']['workspace'])), 'listMembers', 200);
            // A member sees the same list without the addresses of the others.
            contractCheck(contractCall($test, $world['team']['viewer'], 'listMembers', 'GET', contractMemberUri($world['team']['workspace'])), 'listMembers', 200);
        },

        'updateMemberRole' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'updateMemberRole', 'PATCH', contractMemberUri($world['team']['workspace'], $world['team']['editor']), ['role' => 'viewer']), 'updateMemberRole', 200);
        },

        'removeMember' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'removeMember', 'DELETE', contractMemberUri($world['team']['workspace'], $world['team']['editor'])), 'removeMember', 204);
        },

        'listInvitations' => function (object $test): void {
            $world = contractWorld();
            contractInvitation($world['team']['workspace'], 'guest@example.test', $world['owner']);
            contractInvitation($world['team']['workspace'], 'gone@example.test');

            contractCheck(contractCall($test, $world['owner'], 'listInvitations', 'GET', "/api/v1/workspaces/{$world['team']['workspace']->ulid}/invitations"), 'listInvitations', 200);
        },

        'createInvitation' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'createInvitation', 'POST', "/api/v1/workspaces/{$world['team']['workspace']->ulid}/invitations", ['email' => 'guest@example.test', 'role' => 'editor']), 'createInvitation', 201);
        },

        'resendInvitation' => function (object $test): void {
            $world = contractWorld();
            $invitation = contractInvitation($world['team']['workspace'], 'guest@example.test', $world['owner']);

            contractCheck(contractCall($test, $world['owner'], 'resendInvitation', 'POST', "/api/v1/workspaces/{$world['team']['workspace']->ulid}/invitations/{$invitation->ulid}/resend"), 'resendInvitation', 200);
        },

        'cancelInvitation' => function (object $test): void {
            $world = contractWorld();
            $invitation = contractInvitation($world['team']['workspace'], 'guest@example.test', $world['owner']);

            contractCheck(contractCall($test, $world['owner'], 'cancelInvitation', 'DELETE', "/api/v1/workspaces/{$world['team']['workspace']->ulid}/invitations/{$invitation->ulid}"), 'cancelInvitation', 204);
        },

        'listMyInvitations' => function (object $test): void {
            $world = contractWorld();
            $guest = $world['team']['stranger'];
            contractInvitation($world['team']['workspace'], $guest->email, $world['owner']);
            contractInvitation(app(Workspaces::class)->create($world['team']['editor'], 'Annex'), $guest->email);

            contractCheck(contractCall($test, $guest, 'listMyInvitations', 'GET', '/api/v1/me/invitations'), 'listMyInvitations', 200);
        },

        'acceptInvitation' => function (object $test): void {
            $world = contractWorld();
            $guest = $world['team']['stranger'];
            $invitation = contractInvitation($world['team']['workspace'], $guest->email, $world['owner']);

            contractCheck(contractCall($test, $guest, 'acceptInvitation', 'POST', "/api/v1/me/invitations/{$invitation->ulid}/accept"), 'acceptInvitation', 200);
        },

        'declineInvitation' => function (object $test): void {
            $world = contractWorld();
            $guest = $world['team']['stranger'];
            $invitation = contractInvitation($world['team']['workspace'], $guest->email, $world['owner']);

            contractCheck(contractCall($test, $guest, 'declineInvitation', 'DELETE', "/api/v1/me/invitations/{$invitation->ulid}"), 'declineInvitation', 204);
        },

        'listInventories' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'listInventories', 'GET', "/api/v1/workspaces/{$world['team']['workspace']->ulid}/inventories"), 'listInventories', 200);
        },

        'createInventory' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'createInventory', 'POST', "/api/v1/workspaces/{$world['team']['workspace']->ulid}/inventories", ['name' => 'Cellar']), 'createInventory', 201);
        },

        'getInventory' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'getInventory', 'GET', contractUri($world['inventory'])), 'getInventory', 200);
        },

        'updateInventory' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'updateInventory', 'PATCH', contractUri($world['inventory']), ['name' => 'Renamed']), 'updateInventory', 200);
        },

        'deleteInventory' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'deleteInventory', 'DELETE', contractUri($world['inventory'])), 'deleteInventory', 204);
        },

        'createField' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'createField', 'POST', contractUri($world['inventory'], '/fields'), ['type' => 'select', 'name' => 'Condition', 'options' => ['choices' => [['label' => 'New'], ['label' => 'Used']]]]), 'createField', 201);
        },

        'reorderFields' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'reorderFields', 'PUT', contractUri($world['inventory'], '/fields/order'), ['fields' => [$world['price']->ulid, $world['name']->ulid]]), 'reorderFields', 200);
        },

        'updateField' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'updateField', 'PATCH', contractUri($world['inventory'], "/fields/{$world['name']->ulid}"), ['name' => 'Title']), 'updateField', 200);
        },

        'deleteField' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'deleteField', 'DELETE', contractUri($world['inventory'], "/fields/{$world['name']->ulid}")), 'deleteField', 204);
        },

        'listItems' => function (object $test): void {
            $world = contractWorld();
            $uri = contractUri($world['inventory'], '/items');

            contractCheck(contractCall($test, $world['owner'], 'listItems', 'GET', $uri), 'listItems', 200);

            // With the totals: they are in the answer only when asked for.
            $withTotals = contractCall($test, $world['owner'], 'listItems', 'GET', $uri.'?totals[]='.$world['price']->ulid);
            contractCheck($withTotals, 'listItems', 200);
            expect($withTotals->json('meta.totals'))->not->toBeNull();
        },

        'createItem' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'createItem', 'POST', contractUri($world['inventory'], '/items'), ['values' => [$world['name']->ulid => 'Drill', $world['price']->ulid => '30']]), 'createItem', 201);
        },

        'getItem' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'getItem', 'GET', contractUri($world['inventory'], "/items/{$world['item']->ulid}")), 'getItem', 200);
        },

        'updateItem' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'updateItem', 'PATCH', contractUri($world['inventory'], "/items/{$world['item']->ulid}"), ['values' => [$world['name']->ulid => 'Mallet']]), 'updateItem', 200);
        },

        'deleteItem' => function (object $test): void {
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'deleteItem', 'DELETE', contractUri($world['inventory'], "/items/{$world['item']->ulid}")), 'deleteItem', 204);
        },

        'listItemRevisions' => function (object $test): void {
            $world = contractWorld();

            // The item has its creation; this adds a change, so that both kinds of entry are there.
            contractCall($test, $world['owner'], 'updateItem', 'PATCH', contractUri($world['inventory'], "/items/{$world['item']->ulid}"), ['values' => [$world['name']->ulid => 'Mallet']])->assertOk();

            $response = contractCall($test, $world['owner'], 'listItemRevisions', 'GET', contractUri($world['inventory'], "/items/{$world['item']->ulid}/revisions"));
            contractCheck($response, 'listItemRevisions', 200);
            expect(count($response->json('data')))->toBeGreaterThanOrEqual(2);
        },

        'uploadAttachment' => function (object $test): void {
            $world = contractWorld();
            $response = contractUpload($test, $world['owner'], 'uploadAttachment', contractUri($world['inventory'], "/items/{$world['item']->ulid}/attachments"), attachmentPdf());

            contractCheck($response, 'uploadAttachment', 201);
        },

        'downloadAttachment' => function (object $test): void {
            $world = contractWorld();
            $attachment = contractAttachment($world);
            $uri = contractUri($world['inventory'], "/items/{$world['item']->ulid}/attachments/{$attachment->ulid}");

            $response = contractCall($test, $world['owner'], 'downloadAttachment', 'GET', $uri);
            contractCheck($response, 'downloadAttachment', 200, 'application/pdf');

            // The same file again, by its validator: nothing is sent.
            $etag = $response->headers->get('ETag');
            expect($etag)->not->toBeNull();
            contractCheck(contractCall($test, $world['owner'], 'downloadAttachment', 'GET', $uri, [], ['If-None-Match' => (string) $etag]), 'downloadAttachment', 304);
        },

        'deleteAttachment' => function (object $test): void {
            $world = contractWorld();
            $attachment = contractAttachment($world);

            contractCheck(contractCall($test, $world['owner'], 'deleteAttachment', 'DELETE', contractUri($world['inventory'], "/items/{$world['item']->ulid}/attachments/{$attachment->ulid}")), 'deleteAttachment', 200);
        },

        'getAttachmentThumbnail' => function (object $test): void {
            $world = contractWorld();
            $attachment = contractAttachment($world, true);

            $response = contractCall($test, $world['owner'], 'getAttachmentThumbnail', 'GET', contractUri($world['inventory'], "/items/{$world['item']->ulid}/attachments/{$attachment->ulid}/thumbnail"));
            contractCheck($response, 'getAttachmentThumbnail', 200);
        },

        'listExports' => function (object $test): void {
            $world = contractWorld();
            contractExport($world);
            contractExport($world, ExportFormat::Xlsx, false);

            contractCheck(contractCall($test, $world['owner'], 'listExports', 'GET', contractUri($world['inventory'], '/exports')), 'listExports', 200);
        },

        'createExport' => function (object $test): void {
            Queue::fake();
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'createExport', 'POST', contractUri($world['inventory'], '/exports'), ['format' => 'csv']), 'createExport', 202);
        },

        'getExport' => function (object $test): void {
            $world = contractWorld();
            $export = contractExport($world);

            contractCheck(contractCall($test, $world['owner'], 'getExport', 'GET', contractUri($world['inventory'], "/exports/{$export->ulid}")), 'getExport', 200);
        },

        'deleteExport' => function (object $test): void {
            $world = contractWorld();
            $export = contractExport($world);

            contractCheck(contractCall($test, $world['owner'], 'deleteExport', 'DELETE', contractUri($world['inventory'], "/exports/{$export->ulid}")), 'deleteExport', 204);
        },

        'downloadExport' => function (object $test): void {
            $world = contractWorld();
            $export = contractExport($world);

            contractCheck(contractCall($test, $world['owner'], 'downloadExport', 'GET', contractUri($world['inventory'], "/exports/{$export->ulid}/download")), 'downloadExport', 200, 'text/csv');
        },

        'listImports' => function (object $test): void {
            $world = contractWorld();
            contractImport($world);

            contractCheck(contractCall($test, $world['owner'], 'listImports', 'GET', contractUri($world['inventory'], '/imports')), 'listImports', 200);
        },

        'createImport' => function (object $test): void {
            $world = contractWorld();
            $path = importFile(importCsv([['Name', 'Price'], ['Drill', '30']]));

            $response = contractUpload($test, $world['owner'], 'createImport', contractUri($world['inventory'], '/imports'), importUploaded($path, 'stock.csv'));
            contractCheck($response, 'createImport', 201);
        },

        'getImport' => function (object $test): void {
            $world = contractWorld();
            $import = contractImport($world);

            contractCheck(contractCall($test, $world['owner'], 'getImport', 'GET', contractUri($world['inventory'], "/imports/{$import->ulid}")), 'getImport', 200);
        },

        'updateImport' => function (object $test): void {
            $world = contractWorld();
            $import = contractImport($world);

            contractCheck(contractCall($test, $world['owner'], 'updateImport', 'PATCH', contractUri($world['inventory'], "/imports/{$import->ulid}"), ['has_header' => true, 'delimiter' => 'comma']), 'updateImport', 200);
        },

        'deleteImport' => function (object $test): void {
            $world = contractWorld();
            $import = contractImport($world);

            contractCheck(contractCall($test, $world['owner'], 'deleteImport', 'DELETE', contractUri($world['inventory'], "/imports/{$import->ulid}")), 'deleteImport', 204);
        },

        'startImport' => function (object $test): void {
            Queue::fake();
            $world = contractWorld();
            $path = importFile(importCsv([['Name', 'Price'], ['Drill', '30']]));

            $sent = contractUpload($test, $world['owner'], 'createImport', contractUri($world['inventory'], '/imports'), importUploaded($path, 'stock.csv'))->assertCreated();
            $uri = contractUri($world['inventory'], "/imports/{$sent->json('data.id')}/start");

            contractCheck(contractCall($test, $world['owner'], 'startImport', 'POST', $uri, ['mapping' => $sent->json('data.mapping')]), 'startImport', 202);
        },

        'listVersions' => function (object $test): void {
            $world = contractWorld();
            contractVersion($world);
            contractVersion($world, versionSigners(2), 'Year end');

            contractCheck(contractCall($test, $world['owner'], 'listVersions', 'GET', contractUri($world['inventory'], '/versions')), 'listVersions', 200);
        },

        'createVersion' => function (object $test): void {
            Queue::fake();
            versionDocumenso();
            $world = contractWorld();

            contractCheck(contractCall($test, $world['owner'], 'createVersion', 'POST', contractUri($world['inventory'], '/versions'), ['label' => 'Year end', 'signers' => versionSigners(2)]), 'createVersion', 202);
        },

        'getVersion' => function (object $test): void {
            $world = contractWorld();
            $version = contractVersion($world, versionSigners(1), 'Year end');

            contractCheck(contractCall($test, $world['owner'], 'getVersion', 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}")), 'getVersion', 200);
        },

        'deleteVersion' => function (object $test): void {
            $world = contractWorld();
            $version = contractVersion($world);

            contractCheck(contractCall($test, $world['owner'], 'deleteVersion', 'DELETE', contractUri($world['inventory'], "/versions/{$version->ulid}")), 'deleteVersion', 204);
        },

        'downloadVersionPdf' => function (object $test): void {
            $world = contractWorld();
            $version = contractVersion($world);

            contractCheck(contractCall($test, $world['owner'], 'downloadVersionPdf', 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}/pdf")), 'downloadVersionPdf', 200, 'application/pdf');
        },

        'downloadVersionSnapshot' => function (object $test): void {
            $world = contractWorld();
            $version = contractVersion($world);

            contractCheck(contractCall($test, $world['owner'], 'downloadVersionSnapshot', 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}/snapshot")), 'downloadVersionSnapshot', 200, 'application/json');
        },

        'downloadVersionSignedPdf' => function (object $test): void {
            $world = contractWorld();
            $version = contractSigned($world);

            contractCheck(contractCall($test, $world['owner'], 'downloadVersionSignedPdf', 'GET', contractUri($world['inventory'], "/versions/{$version->ulid}/signed-pdf")), 'downloadVersionSignedPdf', 200, 'application/pdf');
        },

        'cancelVersionSignature' => function (object $test): void {
            $world = contractWorld();
            $version = contractVersion($world, versionSigners(2));

            contractCheck(contractCall($test, $world['owner'], 'cancelVersionSignature', 'POST', contractUri($world['inventory'], "/versions/{$version->ulid}/signature/cancel")), 'cancelVersionSignature', 200);
        },

        'retryVersionSignature' => function (object $test): void {
            $world = contractWorld();
            $version = contractVersion($world, versionSigners(2));
            $version->forceFill(['signature_status' => SignatureStatus::Failed, 'signature_error' => SignatureError::DocumensoUnavailable])->save();
            Queue::fake();

            contractCheck(contractCall($test, $world['owner'], 'retryVersionSignature', 'POST', contractUri($world['inventory'], "/versions/{$version->ulid}/signature/retry")), 'retryVersionSignature', 202);
        },

        'listTemplates' => function (object $test): void {
            templateFixtures();

            contractCheck(contractCall($test, null, 'listTemplates', 'GET', '/api/v1/templates'), 'listTemplates', 200);
        },

        'getTemplate' => function (object $test): void {
            templateFixtures();

            contractCheck(contractCall($test, null, 'getTemplate', 'GET', '/api/v1/templates/sample-tools'), 'getTemplate', 200);
        },
    ];
}

describe('the operations of the document', function (): void {
    it('are each held to a real answer by exactly one scenario', function (): void {
        $documented = array_keys(contractOperations());
        $scenarios = array_keys(contractScenarios());

        sort($documented);
        sort($scenarios);

        expect($scenarios)->toBe($documented);
    });

    it('answer as they document', function (string $operationId): void {
        contractScenarios()[$operationId]($this);
    })->with(fn (): array => array_map(fn (string $operationId): array => [$operationId], array_combine(array_keys(contractScenarios()), array_keys(contractScenarios()))));
});
