<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Lets the front end obtain a fresh CSRF token without reloading the page.
 *
 * The web middleware already writes the current token into the readable
 * XSRF-TOKEN cookie on every response (starting a new session first when the
 * old one expired), so this endpoint has nothing to compute: it only has to be
 * a cheap request that goes through that middleware. The HTTP helper calls it
 * after a 419 and then replays the failed request. The URL is advertised in the
 * `csrf-refresh-url` meta tag, so it can later be swapped for Sanctum's
 * `/sanctum/csrf-cookie` without touching any script.
 */
class RefreshCsrfTokenController extends Controller
{
    /**
     * Answers 204; the new token travels in the XSRF-TOKEN cookie. Never cached,
     * or a stale token could be replayed to the browser.
     */
    public function __invoke(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
