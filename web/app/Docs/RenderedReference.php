<?php

declare(strict_types=1);

namespace App\Docs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The HTML of the long part of the page `/docs/api` (the links, the introduction, every operation and every schema: the view
 * `docs.reference`), rendered once for each language and kept in the application cache.
 *
 * Drawing it takes the better part of a second, almost all of it converting hundreds of texts of the document from Markdown, and it is
 * the same for everybody who reads the same language, so it is not done for each request. Like {@see OpenApi}, the cache holds one entry
 * for each language under a fixed key, next to the version of what the HTML was made from: a new version replaces the entry, so nothing
 * has to be cleared and nothing piles up. A cache that cannot be reached does not fail the request: the HTML is drawn for this one.
 *
 * The version is made of what the HTML depends on: the content of the document, the address of the API (built from `APP_URL`), the files
 * that draw it (the classes of `App\Docs`, the views `docs.*` and the translations of the language: a new release changes the page without
 * changing the document, and must not be shown the old one). Their size and date are read rather than their content, which is cheap
 * enough for every request.
 */
final class RenderedReference
{
    /** The start of the key of the cache entries; the language is added. */
    private const CACHE_KEY = 'docs:api-reference:';

    /**
     * Gets the document and what draws it.
     */
    public function __construct(
        private readonly OpenApi $api,
        private readonly ApiReference $reference,
    ) {}

    /**
     * The HTML of the reference in a language, from the cache when it holds the version that is current.
     */
    public function html(string $locale): HtmlString
    {
        $key = self::CACHE_KEY.$locale;
        $version = $this->version($locale);

        try {
            $cached = Cache::get($key);
        } catch (Throwable $exception) {
            $this->cacheUnusable($exception);
            $cached = null;
        }

        if (is_array($cached) && ($cached['version'] ?? null) === $version && is_string($cached['html'] ?? null)) {
            return new HtmlString($cached['html']);
        }

        $html = view('docs.reference', ['reference' => $this->reference->page()])->render();

        try {
            Cache::forever($key, ['version' => $version, 'html' => $html]);
        } catch (Throwable $exception) {
            $this->cacheUnusable($exception);
        }

        return new HtmlString($html);
    }

    /**
     * What the HTML of a language is made from: the same version, the same HTML.
     */
    public function version(string $locale): string
    {
        $files = [
            ...(glob(app_path('Docs/*.php')) ?: []),
            ...(glob(resource_path('views/docs/*.php')) ?: []),
            ...(glob(resource_path('views/components/docs/*.php')) ?: []),
            lang_path($locale.'.json'),
        ];
        sort($files);

        $made = [];

        foreach ($files as $file) {
            $made[] = $file.':'.(is_file($file) ? filemtime($file).':'.filesize($file) : 'missing');
        }

        return hash('xxh128', implode('|', [$this->api->hash(), $locale, $this->api->baseUrl(), ...$made]));
    }

    /**
     * Says that the cache cannot be used: the reference is then drawn by each request, which is only slower.
     */
    private function cacheUnusable(Throwable $exception): void
    {
        Log::warning('The cache of the API documentation cannot be used: it is drawn again by each request until it can.', ['exception' => $exception]);
    }
}
