<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Pwa\WebManifest;
use Illuminate\Http\Response;

/**
 * `/manifest.webmanifest`: the web app manifest, which makes the application installable ({@see WebManifest}).
 *
 * It keeps no session and sets no cookie (the route runs without them, like `/robots.txt`), and it is reachable before the installation.
 * Its text follows the language of the request, so it is never reused without asking: the browser revalidates it each time.
 */
final class WebManifestController extends Controller
{
    /**
     * The manifest, as `application/manifest+json`.
     */
    public function __invoke(WebManifest $manifest): Response
    {
        return response($manifest->toJson(), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
