<?php

declare(strict_types=1);

use App\Inventories\Attachments\ImageInfo;
use App\Inventories\Attachments\Thumbnails;
use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/attachments.php';

/*
 * The thumbnail made when an image is attached: a JPEG with no metadata, 480 pixels on its longest side at most, the right
 * way up, on a white ground; and when none is made on purpose (too many pixels, not the memory to decode it) or by
 * necessity (a broken file), the file is kept without one.
 */

uses(RefreshDatabase::class);

/**
 * The attachment an upload of the image made, and its thumbnail decoded.
 *
 * @return array{attachment: Attachment, image: GdImage|null, bytes: string|null}
 */
function thumbnailOfUpload(string $bytes, string $name = 'photo.png'): array
{
    $case = attachmentCase();
    attachmentUpload(test(), $case['team']['owner'], $case['files'], attachmentUploadedAs($name, $bytes))->assertCreated();
    $attachment = Attachment::query()->sole();
    $stored = $attachment->thumbnail_path === null ? null : Storage::disk(ATTACHMENT_TEST_DISK)->get($attachment->thumbnail_path);

    return ['attachment' => $attachment, 'image' => $stored === null ? null : imagecreatefromstring($stored), 'bytes' => $stored];
}

/**
 * The colour of a pixel as [red, green, blue].
 *
 * @return array{int, int, int}
 */
function thumbnailPixel(GdImage $image, int $x, int $y): array
{
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

/**
 * Whether a pixel is red (the quarter drawn by the helpers), blue (the ground), or white.
 */
function thumbnailIs(string $colour, GdImage $image, int $x, int $y): bool
{
    [$red, $green, $blue] = thumbnailPixel($image, $x, $y);

    return match ($colour) {
        'red' => $red > 200 && $green < 80 && $blue < 80,
        'blue' => $blue > 200 && $red < 80 && $green < 80,
        'white' => $red > 235 && $green > 235 && $blue > 235,
    };
}

/**
 * The bytes of a PNG whose header claims other dimensions: enough for the header to be read, not to be decoded.
 */
function thumbnailPngClaiming(int $width, int $height): string
{
    $png = attachmentImageBytes('png', 4, 4);

    return substr($png, 0, 16).pack('NN', $width, $height).substr($png, 24);
}

describe('the thumbnail of an image', function (): void {
    it('is a JPEG of 480 pixels on its longest side, in proportion', function (int $width, int $height, int $expectedWidth, int $expectedHeight): void {
        $made = thumbnailOfUpload(attachmentImageBytes('png', $width, $height));
        $size = getimagesizefromstring((string) $made['bytes']);

        expect($made['attachment']->thumbnail_path)->toBe($made['attachment']->path.'.thumb.jpg')
            ->and($size[2])->toBe(IMAGETYPE_JPEG)
            ->and([$size[0], $size[1]])->toBe([$expectedWidth, $expectedHeight])
            ->and([$made['attachment']->width, $made['attachment']->height])->toBe([$width, $height]);
    })->with([
        'landscape' => [1000, 500, 480, 240],
        'portrait' => [300, 1200, 120, 480],
        'square' => [960, 960, 480, 480],
        'barely larger' => [481, 481, 480, 480],
    ]);

    it('is not enlarged: a small image keeps its size', function (): void {
        $made = thumbnailOfUpload(attachmentImageBytes('png', 40, 20));

        expect([imagesx($made['image']), imagesy($made['image'])])->toBe([40, 20]);
    });

    it('is made for a JPEG, a PNG, a GIF and a WebP, and for nothing else', function (string $format, string $mime): void {
        $made = thumbnailOfUpload(attachmentImageBytes($format, 600, 300), 'photo.'.$format);

        expect($made['attachment']->mime)->toBe($mime)
            ->and($made['attachment']->thumbnail_path)->not->toBeNull()
            ->and($made['attachment']->width)->toBe(600)
            ->and(imagesx($made['image']))->toBe(480);
    })->with([
        'jpeg' => ['jpeg', 'image/jpeg'],
        'png' => ['png', 'image/png'],
        'gif' => ['gif', 'image/gif'],
        'webp' => ['webp', 'image/webp'],
    ]);

    it('is not made for a file that is not an image, and the size of the image is not recorded', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $attachment = Attachment::query()->sole();

        expect($attachment->thumbnail_path)->toBeNull()
            ->and($attachment->width)->toBeNull()
            ->and($attachment->height)->toBeNull();
    });

    it('is not made for an SVG, which is neither a type that gets one nor one that is shown in the page', function (): void {
        $made = thumbnailOfUpload('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>', 'logo.svg');

        expect($made['attachment']->thumbnail_path)->toBeNull();
    });

    it('has no metadata, and is a plain JPEG at the quality asked', function (): void {
        $jpeg = attachmentJpegWithOrientation(6, 80, 40);
        $made = thumbnailOfUpload($jpeg, 'camera.jpg');

        // The original has its EXIF data and the thumbnail has none: no orientation, no segment at all.
        expect(str_contains($jpeg, 'Exif'))->toBeTrue()
            ->and(str_contains((string) $made['bytes'], 'Exif'))->toBeFalse()
            ->and(substr((string) $made['bytes'], 0, 2))->toBe("\xFF\xD8");

        // The original is stored as it came, EXIF included.
        expect(Storage::disk(ATTACHMENT_TEST_DISK)->get($made['attachment']->path))->toBe($jpeg);
    });

    it('is flattened on a white ground where the image is transparent', function (): void {
        $made = thumbnailOfUpload(attachmentImageBytes('png', 40, 20, transparent: true));

        // The red quarter is opaque, the rest of the ground is transparent blue.
        expect(thumbnailIs('red', $made['image'], 5, 5))->toBeTrue()
            ->and(thumbnailIs('white', $made['image'], 35, 15))->toBeTrue()
            ->and(thumbnailIs('white', $made['image'], 30, 5))->toBeTrue();
    });

    it('is untouched in colour for an opaque image', function (): void {
        $made = thumbnailOfUpload(attachmentImageBytes('png', 40, 20));

        expect(thumbnailIs('red', $made['image'], 5, 5))->toBeTrue()
            ->and(thumbnailIs('blue', $made['image'], 35, 15))->toBeTrue();
    });
});

describe('the orientation recorded by a camera', function (): void {
    // The stored picture is 40 by 20 with its red quarter at the top left. Where the red quarter is once the picture is the
    // right way up, and the size it then has.
    it('is applied to the pixels', function (int $orientation, string $corner, int $width, int $height): void {
        $made = thumbnailOfUpload(attachmentJpegWithOrientation($orientation, 40, 20), 'camera.jpg');
        $image = $made['image'];
        $quarters = ['top left' => [$width / 4, $height / 4], 'top right' => [$width * 3 / 4, $height / 4], 'bottom left' => [$width / 4, $height * 3 / 4], 'bottom right' => [$width * 3 / 4, $height * 3 / 4]];

        expect([imagesx($image), imagesy($image)])->toBe([$width, $height])
            ->and([$made['attachment']->width, $made['attachment']->height])->toBe([$width, $height]);

        foreach ($quarters as $name => [$x, $y]) {
            expect(thumbnailIs($name === $corner ? 'red' : 'blue', $image, (int) $x, (int) $y))->toBeTrue("The {$name} quarter has the wrong colour for orientation {$orientation}.");
        }
    })->with([
        'as stored' => [1, 'top left', 40, 20],
        'mirrored' => [2, 'top right', 40, 20],
        'upside down' => [3, 'bottom right', 40, 20],
        'flipped' => [4, 'bottom left', 40, 20],
        'transposed' => [5, 'top left', 20, 40],
        'turned a quarter clockwise' => [6, 'top right', 20, 40],
        'transversed' => [7, 'bottom right', 20, 40],
        'turned a quarter counter-clockwise' => [8, 'bottom left', 20, 40],
    ]);

    it('is ignored when it is not a value of the specification', function (): void {
        $made = thumbnailOfUpload(attachmentJpegWithOrientation(9, 40, 20), 'camera.jpg');

        expect([imagesx($made['image']), imagesy($made['image'])])->toBe([40, 20])
            ->and(thumbnailIs('red', $made['image'], 10, 5))->toBeTrue();
    });
});

describe('an image no thumbnail is made for', function (): void {
    it('is kept, with its size, when it has more than 40 million pixels', function (): void {
        $made = thumbnailOfUpload(thumbnailPngClaiming(8000, 5001));

        expect($made['attachment']->thumbnail_path)->toBeNull()
            ->and($made['attachment']->mime)->toBe('image/png')
            ->and([$made['attachment']->width, $made['attachment']->height])->toBe([8000, 5001])
            ->and(storedFiles())->toBe([$made['attachment']->path]);
    });

    it('is made at exactly 40 million pixels', function (): void {
        $info = new ImageInfo(8000, 5000);
        $limit = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '-1');

            // Header only: the file behind it is a 4 by 4 image, which GD cannot read as 8000 by 5000 but which proves the guard let it through.
            $path = tempnam(sys_get_temp_dir(), 'thumb-');
            file_put_contents($path, attachmentImageBytes('png', 4, 4));

            expect((new Thumbnails)->make($path, 'image/png', $info))->not->toBeNull();
        } finally {
            ini_set('memory_limit', (string) $limit);
        }
    });

    it('is kept, with its size, when PHP has not the memory to decode it', function (): void {
        $limit = ini_get('memory_limit');

        try {
            ini_set('memory_limit', (string) (memory_get_usage() + 60 * 1024 * 1024));

            $made = thumbnailOfUpload(thumbnailPngClaiming(4000, 4000));
        } finally {
            ini_set('memory_limit', (string) $limit);
        }

        expect($made['attachment']->thumbnail_path)->toBeNull()
            ->and([$made['attachment']->width, $made['attachment']->height])->toBe([4000, 4000]);
    });

    it('is kept without a thumbnail when it is broken: the header is fine, the data is not', function (): void {
        $made = thumbnailOfUpload(substr(attachmentImageBytes('png', 40, 20), 0, 33).random_bytes(200));

        expect($made['attachment']->thumbnail_path)->toBeNull()
            ->and($made['attachment']->mime)->toBe('image/png')
            ->and(storedFiles())->toBe([$made['attachment']->path]);
    });

    it('is kept without a thumbnail when it is cut short', function (): void {
        $png = attachmentImageBytes('png', 300, 300);
        $made = thumbnailOfUpload(substr($png, 0, (int) (strlen($png) / 2)));

        expect($made['attachment']->name)->toBe('photo.png')
            ->and(storedFiles())->toContain($made['attachment']->path);
    });
});
