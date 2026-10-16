<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Attachments\FileNames;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The answer that hands a stored file to its reader, built so that a file somebody uploaded cannot act as part of the
 * application whatever it contains:
 *
 * - it is a download (`Content-Disposition: attachment`, the name in the two forms of RFC 6266), unless it is shown in the
 *   page on request, and then only an image of a type every browser draws safely (JPEG, PNG, WebP, GIF) or a PDF;
 * - `X-Content-Type-Options: nosniff` keeps the browser to the type it is told, and `Content-Security-Policy: default-src 'none';
 *   sandbox` gives whatever it renders no script, no network and no origin of its own;
 * - the type is the one detected from the content when the file was uploaded, never one the client announced;
 * - `Cache-Control: private`, so that a shared cache does not keep a file only members may read.
 *
 * The file is streamed from the disk: it is never read into memory whole.
 */
final class StoredFileResponse
{
    /** The types that may be shown in the page when the reader asks for it. */
    public const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    /**
     * The file itself.
     *
     * @param  bool  $inline  Whether the reader asked for it to be shown in the page; honoured for {@see self::INLINE_TYPES} only.
     */
    public static function file(Attachment $attachment, AttachmentStorage $storage, bool $inline): StreamedResponse|JsonResponse
    {
        $shown = $inline && in_array($attachment->mime, self::INLINE_TYPES, true);

        return self::stream($attachment->disk, $attachment->path, $attachment->mime, $storage, self::disposition($shown ? 'inline' : 'attachment', $attachment->name), $attachment->size);
    }

    /**
     * The thumbnail of the file, a JPEG shown in the page.
     */
    public static function thumbnail(Attachment $attachment, AttachmentStorage $storage): StreamedResponse|JsonResponse
    {
        if ($attachment->thumbnail_path === null) {
            return ApiError::notFound(__('This file has no thumbnail.'), 'thumbnail_not_found');
        }

        return self::stream($attachment->disk, $attachment->thumbnail_path, 'image/jpeg', $storage, 'inline; filename=thumbnail.jpg', null);
    }

    /**
     * Streams a path of a disk with the headers above.
     *
     * @param  int|null  $size  Its size when it is known, for `Content-Length`.
     */
    private static function stream(string $diskName, string $path, string $type, AttachmentStorage $storage, string $disposition, ?int $size): StreamedResponse|JsonResponse
    {
        $stream = $storage->disk($diskName)->readStream($path);

        if (! is_resource($stream)) {
            return ApiError::notFound(__('This file is no longer available.'), 'file_not_found');
        }

        $headers = [
            'Content-Type' => $type,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    /**
     * The `Content-Disposition` header: the disposition, with the name as an ASCII fallback and as UTF-8 (RFC 6266).
     */
    private static function disposition(string $disposition, string $name): string
    {
        return HeaderUtils::makeDisposition($disposition, $name, str_replace(['%', '/', '\\'], '_', FileNames::asciiFallback($name)));
    }
}
