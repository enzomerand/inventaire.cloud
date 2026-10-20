<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Seo\PageMeta;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends `X-Robots-Tag: noindex` with every answer that must not be indexed, whatever its kind: the pages of the
 * application, the JSON of the API, the errors, the files, the sitemap. The `<meta name="robots">` of the layout says it to
 * the engines that read a page; the header says it to those that read anything else (a JSON answer or a file has no
 * `<head>`), and for the pages too, before they are read.
 *
 * A response that already carries an `X-Robots-Tag` keeps it (the page of a share link sends `noindex, nofollow`).
 *
 * The only answers that do not carry it are the public pages that ask to be indexed: the instance allows it
 * (`app.indexable`, APP_INDEXABLE) and the {@see PageMeta} the page was drawn with does not ask otherwise
 * ({@see PageMeta::mayBeIndexed()}, the very decision the layout follows). Everything else carries it, and so does every
 * answer of an instance that does not allow indexing.
 *
 * Registered as global middleware, so that the answers produced outside the routes (404 pages, the install gate) carry it too.
 */
final class SetRobotsTag
{
    /**
     * Adds the header to the response when it must not be indexed.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // An answer that already says what robots may do with it (the page of a share link, which also asks them not to follow its links)
        // keeps its own header.
        if (! $response->headers->has('X-Robots-Tag') && ! $this->mayBeIndexed($response)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    /**
     * Whether the response is a public page that asks to be indexed: it was drawn from a view that was given a
     * {@see PageMeta} (as `$seo`, which is what the layout reads), and that meta asks for it.
     */
    private function mayBeIndexed(Response $response): bool
    {
        if (! $response instanceof IlluminateResponse || ! $response->isSuccessful()) {
            return false;
        }

        $view = $response->getOriginalContent();
        $seo = $view instanceof View ? ($view->getData()['seo'] ?? null) : null;

        return $seo instanceof PageMeta && $seo->mayBeIndexed();
    }
}
