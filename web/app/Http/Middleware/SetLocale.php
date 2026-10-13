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

        // Tells clients which language the body is in...
        $response->headers->set('Content-Language', $locale);

        // ...and tells shared caches that it depends on the request headers the locale is resolved from.
        $response->setVary('Accept-Language, Cookie', false);

        return $response;
    }
}
