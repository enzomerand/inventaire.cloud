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
 * else: a frame of another site, should one ever be embedded, cannot open it. The features the application never uses
 * are turned off, so that a script that should not be there cannot ask for them either.
 *
 * Features the front uses, such as writing to the clipboard (the "copy" buttons), stay out of the list and keep the
 * browser's default. Only names Chromium knows are listed: it reports an unknown one in the console.
 */
final class SetPermissionsPolicy
{
    /** The policy: one directive per feature, `(self)` for this origin only, `()` for nobody. */
    public const POLICY = 'camera=(self), microphone=(), geolocation=(), display-capture=(), payment=(), '
        .'usb=(), serial=(), hid=(), bluetooth=(), midi=(), accelerometer=(), gyroscope=(), magnetometer=()';

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
