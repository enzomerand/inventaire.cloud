<?php

declare(strict_types=1);

use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionFileName;
use App\Inventories\Versions\VersionStatus;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/versions.php';

/*
 * The download of the two files of a completed version: the official PDF and the data that was frozen. They are streamed from the disk
 * as the file of an export is, and the figures the version gives (size, SHA-256) are those of the bytes that come out.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
    Queue::fake();
});

/**
 * The address of a file of a version.
 */
function versionFileUri(Inventory $inventory, InventoryVersion $version, string $file): string
{
    return "/api/v1/inventories/{$inventory->ulid}/versions/{$version->ulid}/{$file}";
}

describe('the PDF', function (): void {
    it('is the file that was written, as an attachment, never sniffed nor cached', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], 'Year end'));

        $response = attachmentGet($this, $case['team']['viewer'], versionFileUri($case['inventory'], $version, 'pdf'))->assertOk();
        $bytes = $response->streamedContent();

        expect($bytes)->toBe(versionFile($version, (string) $version->pdf_path))
            ->and(substr($bytes, 0, 5))->toBe('%PDF-')
            ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="Tools - version 1.pdf"')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->headers->get('Content-Length'))->toBe((string) $version->pdf_size)
            // `private` and `no-store`, in the order Symfony sorts the directives.
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
            ->and($response->headers->get('Cache-Control'))->not->toContain('public')
            // What the version says of the file is true of the bytes.
            ->and(hash('sha256', $bytes))->toBe($version->pdf_sha256)
            ->and(strlen($bytes))->toBe($version->pdf_size);
    });

    it('gives the same figures in the resource and in the file, for every reader', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        foreach (['owner', 'editor', 'viewer'] as $role) {
            $shown = inventoryCall($this, $case['team'][$role], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}")->assertOk();
            $file = attachmentGet($this, $case['team'][$role], $shown->json('data.pdf.download_url'))->assertOk();

            expect(hash('sha256', $file->streamedContent()))->toBe($shown->json('data.pdf.sha256'));
        }
    });

    it('is a 404 for a stranger', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        attachmentGet($this, $case['team']['stranger'], versionFileUri($case['inventory'], $version, 'pdf'))->assertNotFound();
    });
});

describe('the data', function (): void {
    it('is the snapshot, byte for byte, and its SHA-256 is the one the version gives', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], 'Year end'));

        $shown = inventoryCall($this, $case['team']['viewer'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$version->ulid}")->assertOk();
        $response = attachmentGet($this, $case['team']['viewer'], $shown->json('data.snapshot.download_url'))->assertOk();
        $bytes = $response->streamedContent();
        $snapshot = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        expect($bytes)->toBe(versionFile($version, $version->snapshot_path))
            // `shasum -a 256` on the downloaded file gives the figure of the version.
            ->and(hash('sha256', $bytes))->toBe($shown->json('data.snapshot.sha256'))
            ->and(strlen($bytes))->toBe($shown->json('data.snapshot.size'))
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="Tools - version 1.json"')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->headers->get('Content-Length'))->toBe((string) $version->snapshot_size)
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
            ->and($snapshot['format'])->toBe('inventaire.cloud/official-version')
            ->and($snapshot['version']['number'])->toBe(1)
            ->and($snapshot['version']['label'])->toBe('Year end')
            ->and($snapshot['items'])->toHaveCount(3);
    });

    it('is the state of the inventory at the click, not the one it has now', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        itemIn($case['inventory'], [], $case['team']['owner']);
        $case['inventory']->update(['name' => 'Renamed']);

        $snapshot = json_decode(attachmentGet($this, $case['team']['owner'], versionFileUri($case['inventory'], $version, 'snapshot'))->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        expect($snapshot['items'])->toHaveCount(3)
            ->and($snapshot['inventory']['name'])->toBe('Tools');
    });

    it('is a 404 for a stranger', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        attachmentGet($this, $case['team']['stranger'], versionFileUri($case['inventory'], $version, 'snapshot'))->assertNotFound();
    });
});

describe('a file that is not there yet', function (): void {
    it('is a 409 version_not_ready while the version is being written, and if it failed', function (VersionStatus $status, string $file): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);
        $version->forceFill(['status' => $status, 'error' => $status === VersionStatus::Failed ? VersionError::GenerationFailed : null])->save();

        $response = attachmentGet($this, $case['team']['owner'], versionFileUri($case['inventory'], $version, $file))->assertStatus(409);

        expect($response->json())->toBe(['message' => 'This version is not ready yet.', 'code' => 'version_not_ready']);
    })->with([
        'a pending version, for the PDF' => [VersionStatus::Pending, 'pdf'],
        'a running version, for the PDF' => [VersionStatus::Running, 'pdf'],
        'a failed version, for the PDF' => [VersionStatus::Failed, 'pdf'],
        'a pending version, for the data' => [VersionStatus::Pending, 'snapshot'],
        'a running version, for the data' => [VersionStatus::Running, 'snapshot'],
        'a failed version, for the data' => [VersionStatus::Failed, 'snapshot'],
    ]);

    it('says so in the language of the request', function (): void {
        $case = versionTableCase();
        $version = versionFreeze($case['inventory'], $case['team']['owner']);

        $response = attachmentGet($this, $case['team']['owner'], versionFileUri($case['inventory'], $version, 'pdf'), ['Accept-Language' => 'fr'])->assertStatus(409);

        expect($response->json('message'))->toBe('Cette version n\'est pas encore prête.');
    });
});

describe('a file that is gone', function (): void {
    it('is a 404 file_not_found when it is no longer on the disk', function (string $file): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        Storage::disk(ATTACHMENT_TEST_DISK)->deleteDirectory((string) $version->directory());

        $response = attachmentGet($this, $case['team']['owner'], versionFileUri($case['inventory'], $version, $file))->assertNotFound();

        expect($response->json('code'))->toBe('file_not_found');
    })->with(['pdf', 'snapshot']);

    it('is a 404 for a disk that is not configured any more', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        $version->forceFill(['disk' => 'gone'])->save();

        attachmentGet($this, $case['team']['owner'], versionFileUri($case['inventory'], $version, 'pdf'))->assertNotFound();
    });
});

describe('the name of a file', function (): void {
    it('is the name of the inventory, then the number of the version', function (): void {
        expect(VersionFileName::for('Workshop', 3, 'pdf'))->toBe('Workshop - version 3.pdf')
            ->and(VersionFileName::for('Workshop', 12, 'json'))->toBe('Workshop - version 12.json');
    });

    it('is cleaned up as the name of an export is, and carries both forms of an accented name', function (): void {
        $case = versionTableCase();
        $case['inventory']->update(['name' => 'Matériel / électrique: "A*"']);
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner']));

        $disposition = attachmentGet($this, $case['team']['owner'], versionFileUri($case['inventory'], $version, 'pdf'))->assertOk()->headers->get('Content-Disposition');

        expect($disposition)->toContain('filename="Materiel _ electrique_ _A_ - version 1.pdf"')
            ->and($disposition)->toContain("filename*=utf-8''Mat%C3%A9riel%20_%20%C3%A9lectrique_%20_A_%20-%20version%201.pdf");
    });

    it('is never longer than a file system takes, number and extension included', function (): void {
        $name = VersionFileName::for(str_repeat('é', 300), 123, 'json');

        expect(strlen($name))->toBeLessThanOrEqual(200)
            ->and($name)->toEndWith(' - version 123.json');
    });

    it('has something to say when the inventory has no name worth a file', function (): void {
        expect(VersionFileName::for('...', 2, 'pdf'))->toBe('Inventory - version 2.pdf');
    });
});
