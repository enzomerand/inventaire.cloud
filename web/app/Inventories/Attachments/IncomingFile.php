<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use finfo;
use Illuminate\Http\UploadedFile;

/**
 * A file that was uploaded, as the application keeps it: where it is on the server, the name cleaned up, the type
 * detected from its content, and its size.
 *
 * Nothing the client says about the file is trusted but its name, and that is only kept cleaned up ({@see FileNames}):
 * the type the client announces (`Content-Type` of the part, the extension) is ignored, so a script uploaded as
 * `photo.jpg` is stored as the script it is, and served as a download.
 */
final readonly class IncomingFile
{
    /** The type of a file whose content tells nothing. */
    public const UNKNOWN_TYPE = 'application/octet-stream';

    /**
     * @param  string  $path  Where the uploaded file is on the server.
     * @param  string  $name  The name, cleaned up.
     * @param  string  $mime  The type detected from the content, in lowercase.
     * @param  int  $size  Bytes.
     */
    public function __construct(
        public string $path,
        public string $name,
        public string $mime,
        public int $size,
    ) {}

    /**
     * Describes an uploaded file.
     */
    public static function from(UploadedFile $file): self
    {
        $path = $file->getRealPath() ?: $file->getPathname();

        return new self(
            $path,
            FileNames::sanitize($file->getClientOriginalName()),
            self::detect($path),
            (int) (filesize($path) ?: 0),
        );
    }

    /**
     * The type of a file, from its content (libmagic), or {@see self::UNKNOWN_TYPE} when it says nothing a type can be
     * made of.
     */
    public static function detect(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($mime) || strlen($mime) > 127 || preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#i', $mime) !== 1) {
            return self::UNKNOWN_TYPE;
        }

        return strtolower($mime);
    }

    /**
     * Whether the file is an image of a type that gets a thumbnail (JPEG, PNG, WebP, GIF).
     */
    public function isImage(): bool
    {
        return in_array($this->mime, Thumbnails::IMAGE_TYPES, true);
    }
}
