<?php

declare(strict_types=1);

// Helpers shared by the tests of the photo entry. Each test file loads them with require_once, so a file can be run alone. Their names
// all start with "photo" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Attachments\AttachmentStorage;
use App\Models\Inventory;
use App\Models\PhotoAnalysis;
use App\Models\User;
use App\Models\Workspace;
use App\PhotoEntry\PhotoAnalyzer;
use App\PhotoEntry\PhotoReading;
use App\Settings\InstanceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/attachments.php';
require_once __DIR__.'/inventories.php';

/**
 * A team and an inventory on a fake disk, the way the photo analyses need them.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory}
 */
function photoCase(string $name = 'Stock'): array
{
    attachmentDisk();

    $team = inventoryTeam();

    return ['team' => $team, 'inventory' => inventoryIn($team['workspace'], $name)];
}

/**
 * A photo analysis of an inventory, by a person, with `$count` photos on the fake disk, in a state.
 *
 * @param  array<string, mixed>  $attributes
 */
function photoAnalysisWithFiles(Inventory $inventory, User $user, array $attributes = [], int $count = 2): PhotoAnalysis
{
    $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $user->id, 'disk' => ATTACHMENT_TEST_DISK, 'photo_count' => $count] + $attributes);

    foreach ($count > 0 ? range(1, $count) : [] as $number) {
        Storage::disk(ATTACHMENT_TEST_DISK)->put(photoFolder($analysis).'/'.$number.'.jpg', 'jpeg-'.$number);
    }

    return $analysis;
}

/**
 * The folder of the photos of an analysis on the disk.
 */
function photoFolder(PhotoAnalysis $analysis): string
{
    $inventory = $analysis->inventory()->firstOrFail();

    return AttachmentStorage::analysisDirectory($inventory->workspace()->firstOrFail()->ulid, $inventory->ulid, $analysis->ulid);
}

/**
 * The bytes of a JPEG whose EXIF data says where it was taken (48.8584 N, 2.2945 E), the way a phone does: an orientation, and the GPS
 * block with the latitude, the longitude and their references.
 */
function photoJpegWithGps(int $width = 40, int $height = 20): string
{
    $jpeg = attachmentImageBytes('jpeg', $width, $height);

    // Little-endian TIFF: the header (8 bytes), IFD0 with two entries (Orientation, GPSInfo), the GPS IFD with four entries, then the
    // values that do not fit in the 4 bytes of an entry (the two latitude and longitude triples of rationals, 24 bytes each).
    $ifd0 = 8;
    $gps = $ifd0 + 2 + 2 * 12 + 4;
    $latitude = $gps + 2 + 4 * 12 + 4;
    $longitude = $latitude + 24;
    $rationals = fn (array $parts): string => implode('', array_map(fn (array $part): string => pack('VV', $part[0], $part[1]), $parts));

    $tiff = 'II'.pack('v', 42).pack('V', $ifd0)
        .pack('v', 2).pack('vvVV', 0x0112, 3, 1, 1).pack('vvVV', 0x8825, 4, 1, $gps).pack('V', 0)
        .pack('v', 4)
        .pack('vvV', 0x0001, 2, 2)."N\0\0\0"
        .pack('vvVV', 0x0002, 5, 3, $latitude)
        .pack('vvV', 0x0003, 2, 2)."E\0\0\0"
        .pack('vvVV', 0x0004, 5, 3, $longitude)
        .pack('V', 0)
        .$rationals([[48, 1], [51, 1], [3024, 100]])
        .$rationals([[2, 1], [17, 1], [4020, 100]]);
    $payload = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

/**
 * How many APP1 segments (where EXIF and XMP live) a JPEG holds before its image data.
 */
function photoApp1Segments(string $jpeg): int
{
    $count = 0;
    $offset = 2;

    while ($offset + 4 <= strlen($jpeg) && $jpeg[$offset] === "\xFF" && $jpeg[$offset + 1] !== "\xDA") {
        $count += $jpeg[$offset + 1] === "\xE1" ? 1 : 0;
        $offset += 2 + unpack('n', substr($jpeg, $offset + 2, 2))[1];
    }

    return $count;
}

/**
 * Makes the photo entry usable in a workspace: the administrator turned it on, the instance has a key, the owner consented.
 */
function photoTurnedOn(Workspace $workspace): void
{
    app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);
    config(['services.anthropic.key' => 'sk-ant-test-secret']);
    $workspace->forceFill(['photo_entry_enabled' => true])->save();
}

/**
 * The address of the photo analyses of an inventory, or of one of them.
 */
function photoUri(Inventory $inventory, ?PhotoAnalysis $analysis = null): string
{
    return "/api/v1/inventories/{$inventory->ulid}/photo-analyses".($analysis === null ? '' : "/{$analysis->ulid}");
}

/**
 * A photo to upload: a JPEG, a PNG or a WebP that GD draws, of this size.
 */
function photoUpload(string $name = 'photo.jpg', string $format = 'jpeg', int $width = 40, int $height = 20): UploadedFile
{
    return attachmentUploadedAs($name, attachmentImageBytes($format, $width, $height), 'image/'.$format);
}

/**
 * Sends photos the way the web front does (multipart, signed in as the given person).
 *
 * @param  list<UploadedFile>  $photos
 * @param  array<string, string>  $headers
 */
function photoPost(object $test, ?User $as, Inventory $inventory, array $photos, array $headers = []): TestResponse
{
    if ($as !== null) {
        $test->flushSession();
        app('auth')->forgetGuards();
        $test->actingAs($as);
    }

    return $test->post(photoUri($inventory), ['photos' => $photos], frontend($headers + ['Accept' => 'application/json']));
}

/**
 * An analyzer that says what the test wants it to say, and remembers what it was asked. It is what the application is given
 * while the test lasts.
 *
 * @param  PhotoReading|Throwable  $outcome  What it gives back, or throws.
 * @param  array<int, array{photos: list<string>, fields: list<string>, locale: string}>  $calls  Filled with one entry per call.
 */
function photoAnalyzerSaying(PhotoReading|Throwable $outcome, array &$calls = []): PhotoAnalyzer
{
    $analyzer = new class($outcome, $calls) implements PhotoAnalyzer
    {
        /**
         * @param  array<int, array{photos: list<string>, fields: list<string>, locale: string}>  $calls
         */
        public function __construct(private readonly PhotoReading|Throwable $outcome, private array &$calls) {}

        public function analyze(array $photos, array $fields, string $locale): PhotoReading
        {
            $this->calls[] = ['photos' => $photos, 'fields' => array_map(fn ($field): string => $field->ulid, $fields), 'locale' => $locale];

            return $this->outcome instanceof Throwable ? throw $this->outcome : $this->outcome;
        }
    };

    app()->instance(PhotoAnalyzer::class, $analyzer);

    return $analyzer;
}

/**
 * The options of a select field with this many choices whose labels are long (100 characters), the way a catalogue imported from a
 * spreadsheet might have them.
 *
 * @return array{choices: list<array{label: string}>}
 */
function photoManyChoices(int $count, string $prefix = 'Choice'): array
{
    $choices = [];

    for ($number = 1; $number <= $count; $number++) {
        $choices[] = ['label' => str_pad("{$prefix} ".sprintf('%03d', $number).' ', 100, 'x')];
    }

    return ['choices' => $choices];
}
