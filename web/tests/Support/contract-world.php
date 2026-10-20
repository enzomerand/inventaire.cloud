<?php

declare(strict_types=1);

// The world the contract tests call the API in, and the calls they make with a token (tests/Feature/Docs). Each test file loads it
// with require_once, so a file can be run alone. Their names all start with "contract".

use App\Inventories\Exports\ExportFormat;
use App\Models\Attachment;
use App\Models\Export;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\InventoryVersion;
use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Workspaces\MemberIds;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/contract.php';
require_once __DIR__.'/imports.php';
require_once __DIR__.'/templates.php';
require_once __DIR__.'/tokens.php';
require_once __DIR__.'/versions.php';

/**
 * A world to call the API in: a workspace with its owner, an inventory with a text field, a number field and an item.
 *
 * @return array{team: array<string, mixed>, owner: User, inventory: Inventory, name: InventoryField, price: InventoryField, item: Item}
 */
function contractWorld(): array
{
    $case = importCase();
    $team = $case['team'];
    $inventory = $case['inventory'];
    $name = fieldIn($inventory, 'text', 'Name');
    $price = fieldIn($inventory, 'number', 'Price', ['decimals' => 2]);
    $item = itemIn($inventory, [$name->ulid => 'Hammer', $price->ulid => '12.50'], $team['owner']);

    return ['team' => $team, 'owner' => $team['owner'], 'inventory' => $inventory, 'name' => $name, 'price' => $price, 'item' => $item];
}

/**
 * The address of an inventory, or of what is under it.
 */
function contractUri(Inventory $inventory, string $suffix = ''): string
{
    return "/api/v1/inventories/{$inventory->ulid}{$suffix}";
}

/**
 * A call to an operation with a token that holds the ability the document says it needs (and only that one), the way a script makes it.
 *
 * What the call sends is held to the document first: the body fits the `requestBody` of the operation, and the query holds only
 * parameters the operation lists. A scenario that sends a mistake on purpose (to see the 422) says so with `$valid: false`.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 * @param  bool  $valid  Whether the body is meant to fit the document.
 */
function contractCall(object $test, ?User $as, string $operationId, string $method, string $uri, array $data = [], array $headers = [], bool $valid = true): TestResponse
{
    $ability = contractOperations()[$operationId]['ability'] ?? null;

    expect(contractUndocumentedQuery($operationId, $uri))->toBe([], "{$operationId} is called with query parameters its document does not list");

    if ($valid) {
        expect(contractRequestViolations($operationId, $data))->toBe([], "{$operationId} is called with a body that does not fit its document");
    }

    if ($as === null || $ability === null) {
        // No token: the operation is public.
        $test->flushSession();
        $test->flushHeaders();
        app('auth')->forgetGuards();

        return $test->json($method, $uri, $data, $headers);
    }

    return tokenCall($test, tokenFor($as, [$ability]), $method, $uri, $data, $headers);
}

/**
 * Sends a file to an operation with a token that holds the ability the document says it needs. The fields of the form other than the
 * file are held to the document as `contractCall()` holds a body.
 *
 * @param  array<string, mixed>  $data  More parts of the form.
 */
function contractUpload(object $test, User $as, string $operationId, string $uri, UploadedFile $file, array $data = []): TestResponse
{
    expect(contractRequestViolations($operationId, ['file' => 'the bytes of the file'] + $data, 'multipart/form-data'))->toBe([], "{$operationId} is called with a form that does not fit its document");

    $token = tokenFor($as, [(string) contractOperations()[$operationId]['ability']]);

    return tokenUpload($test, $token, $uri, $file, $data);
}

/**
 * An attachment of the item of the world: a PDF, or a PNG whose thumbnail exists.
 */
function contractAttachment(array $world, bool $image = false): Attachment
{
    return attachmentTo($world['inventory'], $world['item'], $image ? attachmentImage() : attachmentPdf(), $world['owner']);
}

/**
 * An export of the inventory of the world, finished and with its file.
 */
function contractExport(array $world, ExportFormat $format = ExportFormat::Csv, bool $stored = true): Export
{
    $export = exportRow($world['inventory'], $world['owner'], ['format' => $format]);

    return $stored ? exportStored($export, 'export.'.$format->value, "Name,Price\r\nHammer,12.50\r\n") : $export;
}

/**
 * An import of the inventory of the world, sent and analysed, which nobody started.
 *
 * @param  array<string, mixed>  $attributes
 */
function contractImport(array $world, array $attributes = []): Import
{
    $import = importWithFile($world['inventory'], $world['owner'], $attributes);

    return importStored($import, "Name,Price\r\nDrill,30\r\n");
}

/**
 * An official version of the inventory of the world, frozen for real and with its PDF written (the queue of the tests is synchronous).
 * With signers, the instance is given a (fake) Documenso so that they can be asked for: the request is sent, so the version is `awaiting` them.
 *
 * @param  list<array{name: string, email: string}>  $signers
 */
function contractVersion(array $world, array $signers = [], ?string $label = null): InventoryVersion
{
    if ($signers !== []) {
        // Their request is sent as the version is completed (the queue of the tests is synchronous): to a Documenso that answers.
        versionDocumenso();
    }

    $version = versionFreeze($world['inventory'], $world['owner'], $label, $signers);

    return $version->fresh() ?? $version;
}

/**
 * An official version whose signers have all signed: its request was sent to a fake Documenso (`awaiting`), the envelope was completed
 * there, and the request was read again, so that the signed PDF is stored.
 *
 * @param  list<array{name: string, email: string}>  $signers
 */
function contractSigned(array $world, array $signers = []): InventoryVersion
{
    $fake = versionDocumenso();
    $version = versionFreeze($world['inventory'], $world['owner'], 'Year end', $signers === [] ? versionSigners(2) : $signers);
    $fake->complete();

    return versionSync($version->fresh() ?? $version);
}

/**
 * The address of the members of the workspace of the world, or of one of them.
 */
function contractMemberUri(Workspace $workspace, ?User $member = null): string
{
    return "/api/v1/workspaces/{$workspace->ulid}/members".($member === null ? '' : '/'.app(MemberIds::class)->of($workspace, $member));
}

/**
 * An invitation to an address, as the table holds it (nothing is mailed), last sent an hour ago so that it can be sent again.
 *
 * @param  array<string, mixed>  $attributes
 */
function contractInvitation(Workspace $workspace, string $email, ?User $by = null, array $attributes = []): WorkspaceInvitation
{
    return WorkspaceInvitation::factory()->create($attributes + [
        'workspace_id' => $workspace->id,
        'email' => $email,
        'invited_by_id' => $by?->id,
        'updated_at' => now()->subHour(),
    ]);
}
