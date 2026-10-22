<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * `/offline`: the page the service worker shows, in place of the browser's error page, when a navigation fails because the network is down.
 *
 * The worker keeps a copy of it when it installs, so the page is made to be copied: it is the same for everybody (nothing of the visitor
 * in it, no CSRF token), it needs no script, no stylesheet and no image of the server (its style and its icon are inline), and it keeps no
 * session and sets no cookie. It is not meant to be found: robots are asked not to index it.
 */
final class OfflineController extends Controller
{
    /**
     * The page.
     */
    public function __invoke(): Response
    {
        return response()->view('pwa.offline');
    }
}
