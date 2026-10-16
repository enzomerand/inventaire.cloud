<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The addresses of the public pages (the home page and the gallery of templates), as search engines and other sites must
 * see them: absolute, and built from `APP_URL`, never from the request. A `Host` header is written by whoever calls, so an
 * address made from it could be made to point anywhere (the same rule as the welcome mail's link to the sign-in page).
 *
 * The pages of the gallery exist in every supported language: the one of the instance (`app.default_locale`) has no prefix
 * in its path, the others have their code (`/en/templates`). The routes that serve these paths are in `routes/web.php`;
 * `PublicPagesTest` checks that every address given here is served.
 */
final class PublicUrls
{
    /**
     * The address of the instance, without a trailing slash.
     */
    public static function root(): string
    {
        return rtrim(config()->string('app.url'), '/');
    }

    /**
     * The absolute address of a path of the application.
     */
    public static function to(string $path): string
    {
        return self::root().$path;
    }

    /**
     * The home page.
     */
    public static function home(): string
    {
        return self::to('/');
    }

    /**
     * The gallery of templates in a language.
     */
    public static function templates(string $locale): string
    {
        return self::to(self::prefix($locale).'/templates');
    }

    /**
     * The page of a template in a language.
     */
    public static function template(string $slug, string $locale): string
    {
        return self::to(self::prefix($locale).'/templates/'.rawurlencode($slug));
    }

    /**
     * The start of the path of the pages in a language: nothing for the language of the instance, `/<code>` for the others.
     */
    public static function prefix(string $locale): string
    {
        return $locale === config()->string('app.default_locale') ? '' : '/'.$locale;
    }
}
