<?php

declare(strict_types=1);

namespace App\PhotoEntry;

/**
 * Why a photo analysis failed. The value is the code stored in `photo_analyses.error` and the one the API gives out as `error`: the words
 * belong to the front, which tells the person in their language (`photoEntry.error.*` in `ClientMessages`), so a code added here needs
 * its message there and a line in `ANALYSIS_FAILURES` (`resources/js/photo-entry/proposals.js`), with whether trying again can help.
 *
 * The codes tell the person what to do next, not what went wrong inside: `unavailable` is worth trying again, `misconfigured` is for the
 * administrator (the key is refused, the model is unknown, the account of the key has no credit left), the others are about the photos.
 */
enum PhotoAnalysisError: string
{
    /** Claude declined to look at the photos. */
    case Refused = 'refused';

    /** The answer was cut short: it did not fit in the room the request allows. */
    case Truncated = 'truncated';

    /** The answer was not what was asked for (not JSON, or not the shape of the schema). */
    case InvalidAnswer = 'invalid_answer';

    /** The service could not be reached, is overloaded or asked to slow down: trying again later may work. */
    case Unavailable = 'unavailable';

    /** The instance is not set up for it: the key, the model or the account of the key is refused. */
    case Misconfigured = 'misconfigured';

    /** Anything else. */
    case Failed = 'failed';

    /** An analysis that never ended: its worker was lost (see {@see PhotoAnalysisPruner}). */
    case Stale = 'stale';

    /** The photo entry was turned off between the moment the photos were received and the moment the job ran: the owner withdrew consent, the administrator switched it off or removed the key. */
    case Disabled = 'disabled';
}
