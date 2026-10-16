<?php

declare(strict_types=1);

// Helpers shared by the tests of attachments, thumbnails, file and photo fields and the purge of stored files. Each test
// file loads them with require_once, so a file can be run alone. Their names all start with "attachment" or "stored" so that
// they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Attachments\Attachments;
use App\Models\Attachment;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/inventories.php';

/** The name of the fake disk the attachments of a test are written to. */
const ATTACHMENT_TEST_DISK = 'attachments-test';

/**
 * Sends the attachments of this test to a fake disk (nothing reaches the real storage).
 */
function attachmentDisk(): void
{
    Storage::fake(ATTACHMENT_TEST_DISK);
    config(['inventories.attachments_disk' => ATTACHMENT_TEST_DISK]);
}

/**
 * A team, an inventory and an item, with the address of the item and of its attachments.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, item: Item, uri: string, files: string}
 */
function attachmentCase(): array
{
    attachmentDisk();

    $team = inventoryTeam();
    $inventory = inventoryIn($team['workspace']);
    $item = itemIn($inventory);

    return ['team' => $team, 'inventory' => $inventory, 'item' => $item, 'uri' => "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}", 'files' => "/api/v1/inventories/{$inventory->ulid}/items/{$item->ulid}/attachments"];
}

/**
 * An uploaded file with the given name and content, and the type the client claims (which the application ignores).
 */
function attachmentUploadedAs(string $name, string $content, ?string $clientType = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'attachment-test-');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, $clientType, null, true);
}

/**
 * A small PDF-looking file (libmagic detects `application/pdf` from the header).
 */
function attachmentPdf(string $name = 'invoice.pdf'): UploadedFile
{
    return attachmentUploadedAs($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n", 'application/pdf');
}

/**
 * The bytes of an image of a type, drawn with GD: a red top-left quarter on a blue ground, optionally partly transparent.
 *
 * @param  'png'|'jpeg'|'gif'|'webp'  $format
 */
function attachmentImageBytes(string $format = 'png', int $width = 40, int $height = 20, bool $transparent = false): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocatealpha($image, 0, 0, 255, $transparent ? 127 : 0));
    imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, (int) imagecolorallocate($image, 255, 0, 0));

    ob_start();

    match ($format) {
        'png' => imagepng($image),
        'gif' => imagegif($image),
        'webp' => imagewebp($image, null, 90),
        default => imagejpeg($image, null, 95),
    };

    return (string) ob_get_clean();
}

/**
 * An uploaded image.
 *
 * @param  'png'|'jpeg'|'gif'|'webp'  $format
 */
function attachmentImage(string $name = 'photo.png', string $format = 'png', int $width = 40, int $height = 20, bool $transparent = false): UploadedFile
{
    return attachmentUploadedAs($name, attachmentImageBytes($format, $width, $height, $transparent), 'image/'.$format);
}

/**
 * The bytes of a JPEG that records an EXIF orientation (1 to 8), the way a camera does.
 */
function attachmentJpegWithOrientation(int $orientation, int $width = 40, int $height = 20): string
{
    $jpeg = attachmentImageBytes('jpeg', $width, $height);
    $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0).pack('N', 0);
    $payload = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

/**
 * Uploads a file to the attachments of an item, the way the web front does (multipart, signed in as the given person).
 *
 * @param  array<string, mixed>  $data  More parts of the form (`field`).
 * @param  array<string, string>  $headers
 */
function attachmentUpload(object $test, ?User $as, string $uri, UploadedFile $file, array $data = [], array $headers = []): TestResponse
{
    if ($as !== null) {
        $test->flushSession();
        app('auth')->forgetGuards();
        $test->actingAs($as);
    }

    return $test->post($uri, ['file' => $file] + $data, frontend($headers + ['Accept' => 'application/json']));
}

/**
 * Reads an address of the API (a download, a thumbnail) as the web front does.
 *
 * @param  array<string, string>  $headers
 */
function attachmentGet(object $test, ?User $as, string $uri, array $headers = []): TestResponse
{
    if ($as !== null) {
        $test->flushSession();
        app('auth')->forgetGuards();
        $test->actingAs($as);
    }

    return $test->get($uri, frontend($headers));
}

/**
 * Attaches a file the way the application does, to an item, and returns the row.
 */
function attachmentTo(Inventory $inventory, Item $item, ?UploadedFile $file = null, ?User $by = null, ?InventoryField $field = null): Attachment
{
    return app(Attachments::class)->upload($inventory, $item, $file ?? attachmentPdf(), $by ?? inventoryPerson('Uploader'), $field?->ulid);
}

/**
 * Every file on the fake disk of the test, sorted.
 *
 * @return list<string>
 */
function storedFiles(): array
{
    $files = Storage::disk(ATTACHMENT_TEST_DISK)->allFiles();
    sort($files);

    return $files;
}
