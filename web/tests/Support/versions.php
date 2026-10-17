<?php

declare(strict_types=1);

// Helpers shared by the tests of official versions. Each test file loads them with require_once, so a file can be run alone. Their
// names all start with "version" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Versions\OfficialVersions;
use App\Inventories\Versions\VersionGenerator;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\User;
use App\Signatures\SignatureRequests;
use App\Signatures\SignatureSync;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDocumenso;

require_once __DIR__.'/exports.php';

/**
 * An instance that has a Documenso, with the settings only (a test that fakes the network itself uses this one).
 */
function versionDocumensoConfig(): void
{
    config(['services.documenso.url' => 'https://sign.example.test', 'services.documenso.api_key' => 'api_test_key', 'services.documenso.webhook_secret' => 'secret-test']);
}

/**
 * An instance that has a Documenso, and a Documenso for it: nothing is ever sent for real, the network is a {@see FakeDocumenso} that
 * answers like the real one and refuses any other request. A test that freezes a version with signers needs it even when it does not
 * look at Documenso: the queue of the tests is synchronous, so the request for signatures is sent as the version is completed.
 */
function versionDocumenso(): FakeDocumenso
{
    versionDocumensoConfig();

    return FakeDocumenso::install();
}

/**
 * Sends the request for signatures of a version now, in the process, as the `RequestSignatures` job would (the caller fakes the queue and
 * the network: see {@see FakeDocumenso::install()}).
 */
function versionSend(InventoryVersion $version): InventoryVersion
{
    app(SignatureRequests::class)->send($version);

    return $version->fresh() ?? $version;
}

/**
 * Looks at the envelope of a version now, in the process, as the `SyncSignature` job would.
 */
function versionSync(InventoryVersion $version): InventoryVersion
{
    app(SignatureSync::class)->sync($version->id);

    return $version->fresh() ?? $version;
}

/**
 * A version of a table inventory whose request for signatures was sent: frozen, its PDF written, its envelope created and distributed
 * at a fake Documenso, `awaiting` its signers. The caller fakes the queue.
 *
 * @param  array{team: array<string, mixed>, inventory: Inventory}|null  $case  The inventory to freeze; a new one when null.
 * @return array{case: array{team: array<string, mixed>, inventory: Inventory}, version: InventoryVersion, fake: FakeDocumenso}
 */
function versionAwaiting(int $signers = 2, ?array $case = null): array
{
    $fake = versionDocumenso();
    $case ??= versionTableCase();
    $version = versionSend(versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners($signers))));

    return ['case' => $case, 'version' => $version, 'fake' => $fake];
}

/**
 * Freezes an inventory the way the application does, without the HTTP. The job is not run: the caller fakes the queue.
 *
 * @param  list<array{name: string, email: string}>  $signers
 */
function versionFreeze(Inventory $inventory, User $by, ?string $label = null, array $signers = []): InventoryVersion
{
    return app(OfficialVersions::class)->create($inventory, $by, $label, $signers);
}

/**
 * Writes the PDF of a version now, in the process, as the queue would.
 */
function versionRun(InventoryVersion $version): InventoryVersion
{
    app(VersionGenerator::class)->run($version);

    return $version->fresh() ?? $version;
}

/**
 * The bytes of a file of a version, as stored on the fake disk.
 */
function versionFile(InventoryVersion $version, string $path): string
{
    return (string) Storage::disk(ATTACHMENT_TEST_DISK)->get($path);
}

/**
 * The snapshot of a version, decoded.
 *
 * @return array<string, mixed>
 */
function versionSnapshot(InventoryVersion $version): array
{
    $decoded = json_decode(versionFile($version, $version->snapshot_path), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * An inventory of four fields (a table, in a PDF) with a few items, one of which has two photos.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory}
 */
function versionTableCase(int $items = 3): array
{
    attachmentDisk();
    config(['app.display_timezone' => 'Europe/Paris']);
    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace'], 'Tools');
    $name = fieldIn($inventory, 'text', 'Name');
    $price = fieldIn($inventory, 'money', 'Price', ['currency' => 'EUR']);
    $bought = fieldIn($inventory, 'date', 'Bought');
    $photos = fieldIn($inventory, 'photo', 'Photos', ['max_files' => 3]);

    for ($i = 1; $i <= $items; $i++) {
        $item = itemIn($inventory, [$name->ulid => "Tool {$i}", $price->ulid => (string) ($i * 1234.5), $bought->ulid => '2026-03-0'.min($i, 9)], $team['owner']);

        if ($i === 1) {
            attachmentTo($inventory, $item, attachmentImage('a.png'), $team['owner'], $photos);
            attachmentTo($inventory, $item, attachmentImage('b.png'), $team['owner'], $photos);
        }
    }

    return ['team' => $team, 'inventory' => $inventory];
}

/**
 * The people to ask a signature from, `$count` of them, on a domain nobody owns.
 *
 * @return list<array{name: string, email: string}>
 */
function versionSigners(int $count = 2): array
{
    $signers = [];

    for ($i = 1; $i <= $count; $i++) {
        $signers[] = ['name' => "Signer {$i}", 'email' => "signer{$i}@example.test"];
    }

    return $signers;
}

/**
 * The number of pages of a PDF.
 */
function versionPdfPages(string $pdf): int
{
    return preg_match_all('/\/Type\s*\/Page(?![a-z])/', $pdf);
}

/**
 * The signature areas a PDF draws, read back from its pages: the inside of the border of each empty area, in points, the origin at the
 * top left of the page (the file counts from the bottom: the rectangle of a stroke is the middle of its line, 0.75 points wide).
 *
 * This is the other side of the figures the application stores for the signature service: they must be the ones the page shows.
 *
 * @return list<array{x: float, y: float, width: float, height: float}>
 */
function versionDrawnAreas(string $pdf, float $pageHeight = 595.28): array
{
    $areas = [];

    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

    foreach ($streams[1] as $raw) {
        $content = @gzuncompress($raw);

        if ($content === false) {
            continue;
        }

        // The border of an area is the only 0.75 point line drawn in #52606d around a rectangle.
        preg_match_all('/0\.322 0\.376 0\.427 RG\n0\.75 w 0 J \[  \] 0 d\n([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) re S/', $content, $found, PREG_SET_ORDER);

        foreach ($found as $rectangle) {
            $areas[] = [
                'x' => (float) $rectangle[1] + 0.375,
                'y' => $pageHeight - ((float) $rectangle[2] + (float) $rectangle[4] - 0.375),
                'width' => (float) $rectangle[3] - 0.75,
                'height' => (float) $rectangle[4] - 0.75,
            ];
        }
    }

    return $areas;
}
