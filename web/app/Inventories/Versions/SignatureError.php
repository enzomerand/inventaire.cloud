<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

/**
 * Why the request for signatures of a version failed: the code stored with it (`inventory_versions.signature_error`) and what a
 * person is told.
 */
enum SignatureError: string
{
    /** Documenso could not be reached, or kept answering with errors. */
    case DocumensoUnavailable = 'documenso_unavailable';

    /** Documenso refused the request: the token, the address or the document is not accepted. */
    case DocumensoRefused = 'documenso_refused';

    /** The envelope is gone from Documenso. */
    case EnvelopeMissing = 'envelope_missing';

    /** The request never ended: its worker stopped, or the queue was not running. */
    case Stale = 'stale';

    /**
     * What a person is told, in the language of the request.
     */
    public function message(): string
    {
        return match ($this) {
            self::DocumensoUnavailable => __('The signature service could not be reached. Try again later.'),
            self::DocumensoRefused => __('The signature service refused the request. Check its configuration, then try again.'),
            self::EnvelopeMissing => __('The signature request no longer exists at the signature service.'),
            self::Stale => __('The signature request did not finish and was cancelled. Try again.'),
        };
    }
}
