<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Pwa\ServiceWorkerScript;
use Illuminate\Http\Response;

/**
 * `/sw.js`: the service worker of the application ({@see ServiceWorkerScript}).
 *
 * It is a route, not a file of `public/`: the worker carries a version computed from the application, and a file would also be served
 * by the web server before the application. It keeps no session and sets no cookie, and it is reachable before the installation.
 *
 * `Cache-Control: no-cache` makes the browser ask each time (it never trusts its copy of a worker for more than a day anyway), and
 * `Service-Worker-Allowed: /` states the scope the worker may be registered for: the whole site, the one of the manifest.
 */
final class ServiceWorkerController extends Controller
{
    /**
     * The script, as JavaScript.
     */
    public function __invoke(ServiceWorkerScript $script): Response
    {
        return response($script->body(), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
