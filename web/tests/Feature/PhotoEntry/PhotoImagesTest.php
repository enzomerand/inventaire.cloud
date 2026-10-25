<?php

declare(strict_types=1);

use App\PhotoEntry\PhotoImages;
use App\PhotoEntry\UnreadablePhotoException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/photo-entry.php';
require_once __DIR__.'/../../Support/imagejpeg-failure.php';

/*
 * The photos as they are sent to Claude: a JPEG of 1568 pixels at most, upright, and nothing but the pixels (no EXIF, no GPS).
 */

uses(RefreshDatabase::class);

/**
 * A scratch file with these bytes, deleted when the process ends.
 */
function photoScratch(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'photo-test-');
    file_put_contents($path, $bytes);
    register_shutdown_function(fn (): bool => @unlink($path));

    return $path;
}

describe('a photo', function (): void {
    it('comes out without any EXIF data: the place it was taken is gone', function (): void {
        $original = photoJpegWithGps(1200, 800);
        $before = exif_read_data('data://image/jpeg;base64,'.base64_encode($original), null, true);

        // The fixture really carries a position, so the test proves something.
        expect($before)->toHaveKey('GPS')
            ->and($before['GPS']['GPSLatitudeRef'])->toBe('N')
            ->and($original)->toContain('Exif');

        $prepared = app(PhotoImages::class)->prepare(photoScratch($original));

        expect(@exif_read_data('data://image/jpeg;base64,'.base64_encode($prepared), null, true))->not->toHaveKey('GPS')
            ->and($prepared)->not->toContain('Exif')
            ->and(photoApp1Segments($prepared))->toBe(0)
            ->and(IMAGETYPE_JPEG)->toBe(getimagesizefromstring($prepared)[2]);
    });

    it('is brought down to 1568 pixels on its longest side, keeping its proportions', function (): void {
        $wide = getimagesizefromstring(app(PhotoImages::class)->prepare(photoScratch(attachmentImageBytes('jpeg', 3136, 1000))));
        $tall = getimagesizefromstring(app(PhotoImages::class)->prepare(photoScratch(attachmentImageBytes('png', 900, 2352))));

        expect([$wide[0], $wide[1]])->toBe([1568, 500])
            ->and([$tall[0], $tall[1]])->toBe([600, 1568]);
    });

    it('is never enlarged', function (): void {
        $size = getimagesizefromstring(app(PhotoImages::class)->prepare(photoScratch(attachmentImageBytes('jpeg', 300, 200))));

        expect([$size[0], $size[1]])->toBe([300, 200]);
    });

    it('is turned upright by its EXIF orientation', function (): void {
        // 40 x 20 stored, orientation 6 (a quarter turn): shown 20 x 40.
        $size = getimagesizefromstring(app(PhotoImages::class)->prepare(photoScratch(attachmentJpegWithOrientation(6))));

        expect([$size[0], $size[1]])->toBe([20, 40]);
    });

    it('is accepted as a PNG or a WebP and comes out a JPEG, a transparent ground turned white', function (string $format): void {
        $prepared = app(PhotoImages::class)->prepare(photoScratch(attachmentImageBytes($format, 64, 64, transparent: true)));
        $image = imagecreatefromstring($prepared);
        $corner = imagecolorsforindex($image, imagecolorat($image, 60, 60));

        expect(getimagesizefromstring($prepared)[2])->toBe(IMAGETYPE_JPEG)
            ->and($corner['red'])->toBeGreaterThan(240)->and($corner['green'])->toBeGreaterThan(240)->and($corner['blue'])->toBeGreaterThan(240);
    })->with(['png', 'webp']);

    it('is refused when it is not an image of an accepted type', function (string $bytes, string $reason): void {
        $refused = null;

        try {
            app(PhotoImages::class)->prepare(photoScratch($bytes));
        } catch (UnreadablePhotoException $exception) {
            $refused = $exception->reason;
        }

        expect($refused)->toBe($reason);
    })->with([
        'a text' => ['hello', UnreadablePhotoException::UNSUPPORTED_TYPE],
        'a PDF' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n", UnreadablePhotoException::UNSUPPORTED_TYPE],
        'a GIF' => [attachmentImageBytes('gif'), UnreadablePhotoException::UNSUPPORTED_TYPE],
    ]);

    it('is refused when it cannot be decoded', function (): void {
        $jpeg = attachmentImageBytes('jpeg', 200, 200);
        $broken = substr($jpeg, 0, 160).str_repeat("\x00", 40);
        $refused = null;

        try {
            app(PhotoImages::class)->prepare(photoScratch($broken));
        } catch (UnreadablePhotoException $exception) {
            $refused = $exception->reason;
        }

        // A header that GD reads but a body it cannot decode, or a header it cannot read at all: either way it is refused.
        expect($refused)->toBeIn([UnreadablePhotoException::UNREADABLE]);
    });

    it('is refused when it is an animated WebP, which GD cannot decode', function (): void {
        $refused = null;

        try {
            app(PhotoImages::class)->prepare(photoScratch(attachmentAnimatedWebpBytes()));
        } catch (UnreadablePhotoException $exception) {
            $refused = $exception->reason;
        }

        expect($refused)->toBe(UnreadablePhotoException::UNREADABLE);
    });

    it('is refused when it has too many pixels to be decoded', function (): void {
        // A JPEG header that announces 50 million pixels, with nothing behind it.
        $jpeg = attachmentImageBytes('jpeg', 10, 10);
        $big = substr_replace($jpeg, pack('nn', 5000, 10000), strpos($jpeg, "\xFF\xC0") + 5, 4);
        $refused = null;

        try {
            app(PhotoImages::class)->prepare(photoScratch($big));
        } catch (UnreadablePhotoException $exception) {
            $refused = $exception->reason;
        }

        expect($refused)->toBe(UnreadablePhotoException::TOO_LARGE);
    });

    it('is refused as too large when PHP has not the memory to decode it', function (): void {
        // A JPEG header that announces 12 million pixels (3000 x 4000): fine for the pixel limit, not for a PHP with little memory left.
        $jpeg = attachmentImageBytes('jpeg', 10, 10);
        $big = substr_replace($jpeg, pack('nn', 3000, 4000), strpos($jpeg, "\xFF\xC0") + 5, 4);
        $path = photoScratch($big);
        $limit = (string) ini_get('memory_limit');
        $refused = null;

        ini_set('memory_limit', (string) (memory_get_usage() + 40_000_000));

        try {
            app(PhotoImages::class)->prepare($path);
        } catch (UnreadablePhotoException $exception) {
            $refused = $exception->reason;
        } finally {
            ini_set('memory_limit', $limit);
        }

        expect($refused)->toBe(UnreadablePhotoException::TOO_LARGE);
    });
});

describe('a photo the server cannot prepare', function (): void {
    afterEach(function (): void {
        unset($GLOBALS['photo_imagejpeg_fails']);
    });

    it('is no fault of the photo: a failure of the JPEG encoder is thrown, never told as a photo that is too large', function (): void {
        $GLOBALS['photo_imagejpeg_fails'] = true;
        $thrown = null;

        try {
            app(PhotoImages::class)->prepare(photoScratch(attachmentImageBytes('jpeg', 64, 64)));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class)->not->toBeInstanceOf(UnreadablePhotoException::class)
            ->and($thrown->getMessage())->toContain('could not be prepared');
    });
});
