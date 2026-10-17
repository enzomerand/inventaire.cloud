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
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/contract.php';
require_once __DIR__.'/imports.php';
require_once __DIR__.'/templates.php';
require_once __DIR__.'/tokens.php';

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
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function contractCall(object $test, ?User $as, string $operationId, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    $ability = contractOperations()[$operationId]['ability'] ?? null;

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
 * Sends a file to an operation with a token that holds the ability the document says it needs.
 *
 * @param  array<string, mixed>  $data  More parts of the form.
 */
function contractUpload(object $test, User $as, string $operationId, string $uri, UploadedFile $file, array $data = []): TestResponse
{
    $token = tokenFor($as, [(string) contractOperations()[$operationId]['ability']]);

    $test->flushSession();
    $test->flushHeaders();
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->post($uri, ['file' => $file] + $data, ['Accept' => 'application/json']);
    $test->flushHeaders();

    return $response;
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
