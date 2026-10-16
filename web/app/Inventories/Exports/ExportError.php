<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

/**
 * Why an export failed: the code stored with it (`exports.error`) and what a person is told.
 */
enum ExportError: string
{
    /** The view the export was asked on does not hold any more: a field it filters or sorts by was deleted. */
    case InvalidQuery = 'invalid_query';

    /** The file could not be written. The cause is in the log of the server, not in front of the person. */
    case GenerationFailed = 'generation_failed';

    /** A PDF the library could not lay out in memory: too many items, or too much text. */
    case PdfTooLarge = 'pdf_too_large';

    /** The export never ended: its worker stopped, or the queue was not running. */
    case Stale = 'stale';

    /**
     * What a person is told, in the language of the request.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidQuery => __('The view this export was made on is no longer valid: a field it uses was deleted or changed.'),
            self::GenerationFailed => __('The export could not be generated. Try again.'),
            self::PdfTooLarge => __('This PDF is too large to be written. Filter the view to export fewer items, or choose another format.'),
            self::Stale => __('The export did not finish and was cancelled. Try again.'),
        };
    }

    /**
     * The error for a code read from the database, or null for a code this version does not know.
     */
    public static function fromCode(?string $code): ?self
    {
        return $code === null ? null : self::tryFrom($code);
    }
}
