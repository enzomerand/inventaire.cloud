<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Attachments\FileNames;
use App\Models\Attachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The answer that hands a stored file to its reader, built so that a file somebody uploaded cannot act as part of the
 * application whatever it contains:
 *
 * - it is a download (`Content-Disposition: attachment`, the name in the two forms of RFC 6266), unless it is shown in the
 *   page on request, and then only an image of a type every browser draws safely (JPEG, PNG, WebP, GIF) or a PDF;
 * - `X-Content-Type-Options: nosniff` keeps the browser to the type it is told, and `Content-Security-Policy: default-src 'none';
 *   sandbox` gives whatever it renders no script, no network and no origin of its own. The one exception is a PDF shown in
 *   the page: Chromium does not draw a PDF in a sandboxed document, so it gets `default-src 'none'` alone (the PDF viewer of
 *   a browser runs no script of the file's own and gets no network from the page either);
 * - the type is the one detected from the content when the file was uploaded, never one the client announced;
 * - `Cache-Control: private`, so that a shared cache does not keep a file only members may read. The file itself is
 *   revalidated each time (`no-cache`) with an `ETag` made of its ULID, which is enough since a stored file never changes:
 *   a reader who loses access (removed from the workspace, file deleted) is not served from their cache for an hour, and one
 *   who still has it costs a 304. The thumbnail, a small image shown by the dozen, is kept for an hour.
 *
 * The file is streamed from the disk: it is never read into memory whole. A file that is not on its disk is a 404; a disk that
 * is no longer configured is a 404 too; a disk that cannot be read right now (the bucket is down) is a 503, written in the log.
 */
final class StoredFileResponse
{
    /** The types that may be shown in the page when the reader asks for it. */
    public const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    /** What a file is allowed to do once the browser has it. */
    private const SANDBOX = "default-src 'none'; sandbox";

    /** The same for a PDF shown in the page, which Chromium refuses to draw in a sandbox. */
    private const PDF_VIEWER = "default-src 'none'";

    /**
     * The file itself.
     *
     * @param  Request  $request  Whether the reader asked for it to be shown in the page (`?inline=1`, honoured for {@see self::INLINE_TYPES} only), and which version of it they already have.
     */
    public static function file(Attachment $attachment, AttachmentStorage $storage, Request $request): StreamedResponse|JsonResponse|Response
    {
        $shown = $request->boolean('inline') && in_array($attachment->mime, self::INLINE_TYPES, true);
        $revalidation = ['ETag' => '"'.$attachment->ulid.'"', 'Cache-Control' => 'private, no-cache'];

        // The reader has this very file already: nothing is opened. The caller has checked they may read it.
        $unchanged = new Response('', 200, $revalidation);

        if ($unchanged->isNotModified($request)) {
            return $unchanged;
        }

        $headers = $revalidation + [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => self::disposition($shown ? 'inline' : 'attachment', $attachment->name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => $shown && $attachment->mime === 'application/pdf' ? self::PDF_VIEWER : self::SANDBOX,
            'Content-Length' => (string) $attachment->size,
        ];

        return self::stream($attachment, $attachment->path, $storage, $headers);
    }

    /**
     * The thumbnail of the file, a JPEG shown in the page.
     */
    public static function thumbnail(Attachment $attachment, AttachmentStorage $storage): StreamedResponse|JsonResponse
    {
        if ($attachment->thumbnail_path === null) {
            return ApiError::notFound(__('This file has no thumbnail.'), 'thumbnail_not_found');
        }

        return self::stream($attachment, $attachment->thumbnail_path, $storage, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename=thumbnail.jpg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::SANDBOX,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Streams a path of the disk of the attachment with the headers given.
     *
     * @param  array<string, string>  $headers
     */
    private static function stream(Attachment $attachment, string $path, AttachmentStorage $storage, array $headers): StreamedResponse|JsonResponse
    {
        try {
            $disk = $storage->disk($attachment->disk);
        } catch (InvalidArgumentException $exception) {
            Log::warning('A stored file is on a disk that is not configured any more.', ['disk' => $attachment->disk, 'path' => $path, 'exception' => $exception]);

            return self::gone();
        } catch (Throwable $exception) {
            return self::unavailable($attachment->disk, $path, $exception);
        }

        $failure = null;

        try {
            $stream = $disk->readStream($path);
        } catch (Throwable $exception) {
            $stream = null;
            $failure = $exception;
        }

        if (! is_resource($stream)) {
            return self::unreadable($disk, $attachment->disk, $path, $failure);
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    /**
     * The answer for a path that could not be opened: a 404 when the file is not there, a 503 when it may be and cannot be
     * read. A disk that says nothing about why it gave no stream (Laravel's adapters swallow the failure unless asked to throw)
     * is asked whether the file exists, and a disk that cannot answer that either is down.
     */
    private static function unreadable(Filesystem $disk, string $diskName, string $path, ?Throwable $failure): JsonResponse
    {
        try {
            $missing = ! $disk->exists($path);
        } catch (Throwable) {
            $missing = false;
        }

        return $missing ? self::gone() : self::unavailable($diskName, $path, $failure);
    }

    /**
     * The 404 of a file that is no longer available.
     */
    private static function gone(): JsonResponse
    {
        return ApiError::notFound(__('This file is no longer available.'), 'file_not_found');
    }

    /**
     * The 503 of a file that cannot be read now, with the failure in the log (a person will not read it, an operator must).
     */
    private static function unavailable(string $diskName, string $path, ?Throwable $failure): JsonResponse
    {
        Log::error('A stored file could not be read from its disk.', ['disk' => $diskName, 'path' => $path, 'exception' => $failure]);

        return ApiError::unavailable(__('This file cannot be read right now. Try again in a moment.'), 'file_unavailable');
    }

    /**
     * The `Content-Disposition` header: the disposition, with the name as an ASCII fallback and as UTF-8 (RFC 6266).
     */
    private static function disposition(string $disposition, string $name): string
    {
        return HeaderUtils::makeDisposition($disposition, $name, str_replace(['%', '/', '\\'], '_', FileNames::asciiFallback($name)));
    }
}
