<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a `Permissions-Policy` header with every answer: it tells the browser which of its powerful features a page of the
 * application, and any frame inside it, may ask for.
 *
 * The camera stays allowed for the application's own pages (the barcode scanner, `resources/js/scanner/`) and for nobody
 * else: a frame of another site, should one ever be embedded, cannot open it. The other features the application never
 * uses (the microphone, the location, the motion sensors, the devices plugged in, payments, screen capture) are turned
 * off, so that a script that should not be there (an injected one, a compromised dependency) cannot ask for them either,
 * and so is the Topics API of Chrome (the browser keeps no advertising interests from visits to the application).
 *
 * Features the front does use are left out of the list, so they keep the browser's default (the page itself only):
 * writing to the clipboard (the "copy" buttons), and those a later front may need, such as the full screen, the web
 * share sheet or passkeys. Only names the browsers know are listed, since an unknown one is reported in the console.
 */
final class SetPermissionsPolicy
{
    /** The policy: one directive per feature, `(self)` for this origin only, `()` for nobody. */
    public const POLICY = 'camera=(self), microphone=(), geolocation=(), display-capture=(), payment=(), '
        .'usb=(), serial=(), hid=(), bluetooth=(), midi=(), accelerometer=(), gyroscope=(), magnetometer=(), '
        .'browsing-topics=()';

    /**
     * Adds the header to the response.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Permissions-Policy', self::POLICY);

        return $response;
    }
}
