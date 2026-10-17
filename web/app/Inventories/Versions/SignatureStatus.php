<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

/**
 * Where the request for signatures of a version is: asked for, being sent to Documenso, waiting for the signers, or over.
 */
enum SignatureStatus: string
{
    /** Signers were asked for when the version was frozen; nothing was sent to Documenso yet. */
    case Requested = 'requested';

    /** The envelope is being created at Documenso. */
    case Sending = 'sending';

    /** Documenso has sent the e-mails; the signers have not all signed. */
    case Awaiting = 'awaiting';

    /** Everybody signed: the signed PDF is stored. */
    case Completed = 'completed';

    /** A signer refused to sign. */
    case Rejected = 'rejected';

    /** The request was cancelled, here or at Documenso. */
    case Cancelled = 'cancelled';

    /** The request could not be sent, or was lost: `signature_error` says why. */
    case Failed = 'failed';
}
