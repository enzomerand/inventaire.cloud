<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the interface language of each web request.
 *
 * Priority: a language the visitor picked explicitly (kept in the session), then
 * the best match in the browser's Accept-Language header, then the application
 * default. An explicit choice must win over the browser, otherwise the language
 * switcher would appear to do nothing for people whose browser speaks another
 * language than the one they want here.
 */
class SetLocale
{
    /**
     * Session key under which the visitor's explicit choice is stored.
     */
    public const SESSION_KEY = 'locale';

    /**
     * Applies the resolved locale for the rest of the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);

        // Tells caches and clients which language the body is in.
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /**
     * Picks the locale for the request.
     */
    private function resolve(Request $request): string
    {
        $supported = $this->supported();

        $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_string($chosen) && in_array($chosen, $supported, true)) {
            return $chosen;
        }

        return $this->fromAcceptLanguage($request, $supported) ?? config()->string('app.locale');
    }

    /**
     * Finds the first language the browser asks for that the application supports.
     *
     * Symfony already orders the header by quality value, so the first hit is the
     * visitor's best option. A regional tag ("fr-CH") matches its base language
     * ("fr") when the region itself is not supported.
     *
     * @param  list<string>  $supported
     */
    private function fromAcceptLanguage(Request $request, array $supported): ?string
    {
        foreach ($request->getLanguages() as $candidate) {
            if (in_array($candidate, $supported, true)) {
                return $candidate;
            }

            $language = strtolower(explode('_', $candidate)[0]);

            if (in_array($language, $supported, true)) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Locales the application is translated into.
     *
     * @return list<string>
     */
    private function supported(): array
    {
        return array_values(array_map(strval(...), config()->array('app.supported_locales')));
    }
}
