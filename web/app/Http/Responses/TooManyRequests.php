<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Exceptions\ThrottleRequestsException;

/**
 * The refusal of a request that went beyond a pace, the same for every pace of the application: the framework renders the exception
 * as a `429` (in JSON for the API, see `bootstrap/app.php`) and sends its headers along.
 */
final class TooManyRequests
{
    /**
     * The exception of a pace that is used up.
     *
     * @param  string  $message  What was refused.
     * @param  int  $limit  What the pace allows (`X-RateLimit-Limit`).
     * @param  int  $retryAfter  The seconds until the pace allows a request again (`Retry-After`).
     */
    public static function exception(string $message, int $limit, int $retryAfter): ThrottleRequestsException
    {
        return new ThrottleRequestsException($message, null, [
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => time() + $retryAfter,
        ]);
    }
}
