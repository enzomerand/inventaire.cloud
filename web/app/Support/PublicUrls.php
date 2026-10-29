<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\LocaleResolver;
use Closure;

/**
 * The addresses of the public pages (the home page, the documentation of the API, the gallery of templates and the pricing page) and of the endpoint other
 * services call (the webhook of Documenso), as search engines and other sites must see them: absolute, and built from `APP_URL`, never from the request. A `Host` header is written by whoever calls, so an
 * address made from it could be made to point anywhere (the same rule as the welcome mail's link to the sign-in page).
 *
 * The pages of the gallery and the pricing page exist in every supported language: the one of the instance (`app.default_locale`)
 * has no prefix in its path, the others have their code (`/en/templates`, `/en/pricing`). The routes that serve these paths are in
 * `routes/web.php`.
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
     * The documentation of the API.
     */
    public static function apiDocs(): string
    {
        return self::to(self::apiDocsPath());
    }

    /**
     * The path of the documentation of the API, for a link that stays on the host the visitor came by. It exists once, in the
     * language of the request: the text of the document is English.
     */
    public static function apiDocsPath(): string
    {
        return '/docs/api';
    }

    /**
     * The address Documenso is told to call when something happens to an envelope (`POST`, with the secret in `X-Documenso-Secret`).
     */
    public static function documensoWebhook(): string
    {
        return self::to(route('webhooks.documenso', [], false));
    }

    /**
     * The gallery of templates in a language.
     */
    public static function templates(string $locale): string
    {
        return self::to(self::templatesPath($locale));
    }

    /**
     * The page of a template in a language.
     */
    public static function template(string $slug, string $locale): string
    {
        return self::to(self::templatePath($slug, $locale));
    }

    /**
     * The path of the gallery of templates in a language, for a link that stays on the host the visitor came by.
     */
    public static function templatesPath(string $locale): string
    {
        return self::prefix($locale).'/templates';
    }

    /**
     * The path of the page of a template in a language, for a link that stays on the host the visitor came by.
     */
    public static function templatePath(string $slug, string $locale): string
    {
        return self::templatesPath($locale).'/'.rawurlencode($slug);
    }

    /**
     * The pricing page in a language.
     */
    public static function pricing(string $locale): string
    {
        return self::to(self::pricingPath($locale));
    }

    /**
     * The path of the pricing page in a language, for a link that stays on the host the visitor came by.
     */
    public static function pricingPath(string $locale): string
    {
        return self::prefix($locale).'/pricing';
    }

    /**
     * A page in every language the application supports: what `$path` gives for each language, by language. For the
     * language switcher of a page that exists once per language, and for the `hreflang` links that say so.
     *
     * @param  Closure(string): string  $path  The path or address of the page in a language.
     * @return array<string, string>
     */
    public static function alternates(Closure $path): array
    {
        $alternates = [];

        foreach (app(LocaleResolver::class)->supported() as $locale) {
            $alternates[$locale] = $path($locale);
        }

        return $alternates;
    }

    /**
     * The start of the path of the pages in a language: nothing for the language of the instance, `/<code>` for the others.
     */
    private static function prefix(string $locale): string
    {
        return $locale === config()->string('app.default_locale') ? '' : '/'.$locale;
    }
}
