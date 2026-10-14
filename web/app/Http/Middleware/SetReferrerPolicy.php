<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends `Referrer-Policy: same-origin` with every answer, web pages and API alike.
 *
 * The browser then tells the application which page a call comes from when it
 * stays on the same site, and nobody else: Sanctum recognizes the web front
 * (a "stateful" request, with a session) by the Origin or Referer header, so a
 * policy such as `no-referrer` set in front of the application would make the
 * front's own GET calls (such as `/api/v1/me`) look like those of a stranger,
 * while an unrestricted policy would leak the addresses of the pages to the
 * sites the visitor goes on to.
 */
final class SetReferrerPolicy
{
    /**
     * Adds the header to the response.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
