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

    /**
     * The states in which a request has not ended: it may still change, and the envelope it has at Documenso (if it has one) is worth
     * following, cancelling when its version goes, and reading again. A `failed` request is one of them: it can have an envelope that
     * is pending at Documenso (the answer of the call that sent it was lost), and the person may send it again.
     *
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::Requested, self::Sending, self::Awaiting, self::Failed];
    }

    /**
     * {@see self::unfinished()} as the values stored.
     *
     * @return list<string>
     */
    public static function unfinishedValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::unfinished());
    }
}
