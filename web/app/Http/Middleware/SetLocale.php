<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\LocaleResolver;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the interface language of each request.
 *
 * Registered as global middleware, ahead of everything else, so that responses
 * produced outside the web group (404 pages for unknown routes, 419 and 503
 * errors, and the future JSON API) are localized too. The choice itself is made
 * by {@see LocaleResolver}.
 *
 * It also says what a shared cache may do with the answer. The language comes from the cookie of the switcher, else from
 * the `Accept-Language` of the browser, so the answer varies with both (`Vary: Accept-Language, Cookie`)... except for the
 * public pages whose language is the one of their address ({@see UseUrlLocale}): those do not depend on what the browser
 * says about languages, and vary on the cookie only, because the page shows whether somebody is signed in. And nothing
 * the application draws is kept by a shared cache (`Cache-Control: private, no-cache`), unless the answer decides its own
 * lifetime (a file that may be kept for an hour, a token that must not be stored).
 */
class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    /**
     * Applies the resolved locale for the rest of the request and labels the response.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolver->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);

        // Tells clients which language the body is in (the one the route may have chosen instead: see UseUrlLocale)...
        $response->headers->set('Content-Language', App::getLocale());

        // ...and tells shared caches that it depends on the request headers the locale is resolved from, or on the cookie
        // alone when the address decides the language.
        if ($request->attributes->get(UseUrlLocale::ATTRIBUTE) === true) {
            $this->varyOnCookieOnly($response);
        } else {
            $response->setVary('Accept-Language, Cookie', false);
        }

        if (! $this->decidesItsCaching($response)) {
            $response->headers->set('Cache-Control', 'private, no-cache');
        }

        return $response;
    }

    /**
     * Makes the response vary on the cookie and not on `Accept-Language`, keeping whatever else it varies on.
     */
    private function varyOnCookieOnly(Response $response): void
    {
        $vary = array_values(array_filter($response->getVary(), fn (string $header): bool => strcasecmp($header, 'Accept-Language') !== 0 && strcasecmp($header, 'Cookie') !== 0));

        $response->setVary([...$vary, 'Cookie']);
    }

    /**
     * Whether the response states for how long a cache may keep it, or that it must not: then it keeps its own header.
     */
    private function decidesItsCaching(Response $response): bool
    {
        foreach (['max-age', 's-maxage', 'public', 'no-store', 'must-revalidate', 'immutable'] as $directive) {
            if ($response->headers->hasCacheControlDirective($directive)) {
                return true;
            }
        }

        return false;
    }
}
