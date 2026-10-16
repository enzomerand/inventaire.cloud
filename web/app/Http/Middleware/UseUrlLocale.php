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
 * Fixes the language of a public page from its address: `/templates` is in the language of the instance
 * (`app.default_locale`), `/en/templates` in English, and nothing else decides.
 *
 * A page that exists once per language, with an address each, must say the same thing to everybody who asks for it
 * (that is what makes it indexable in every language), so the cookie of the language switcher and the browser's
 * `Accept-Language`, which {@see SetLocale} looked at first, are overridden, and the cookie is not written: the visitor
 * who follows the link to the other language is not changing their preference for the rest of the application.
 *
 * The routes of the other languages carry the code as the `{locale}` parameter. The language of the instance has one
 * address only, without a prefix: its prefixed address answers with a permanent redirect to it. A code the application does
 * not support does not match any route (404).
 */
final class UseUrlLocale
{
    /**
     * Gets the resolver that knows the supported languages.
     */
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * Applies the language of the address for the rest of the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $default = config()->string('app.default_locale');
        $route = $request->route();
        $prefixed = $route !== null && $route->hasParameter('locale');
        $locale = $prefixed && is_string($route->parameter('locale')) ? $route->parameter('locale') : $default;

        if (! $this->locales->isSupported($locale)) {
            abort(404);
        }

        if ($prefixed && $locale === $default) {
            return redirect()->to($this->withoutPrefix($request, $locale), 301);
        }

        // The controllers do not know the code: the page is the same, it is the language that differs.
        $route?->forgetParameter('locale');

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    /**
     * The address of the request without the code of the language, the query string kept.
     */
    private function withoutPrefix(Request $request, string $locale): string
    {
        $path = '/'.ltrim(substr($request->getPathInfo(), strlen($locale) + 1), '/');
        $query = $request->getQueryString();

        return $path.($query === null || $query === '' ? '' : '?'.$query);
    }
}
