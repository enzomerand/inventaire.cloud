<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Http\Middleware\SetRobotsTag;
use App\Support\PublicUrls;

/**
 * What a public page says about itself to search engines and to the sites that show its link: the title and the
 * description, the one address it is known by, the same page in the other languages, and structured data.
 *
 * The layout draws it as `<meta>` and `<link>` tags and JSON-LD scripts (`components/seo.blade.php`). A page that has none
 * is not public: the layout then asks not to be indexed. Addresses are absolute (see {@see PublicUrls}).
 *
 * Whether the page asks to be indexed is decided once, by {@see self::mayBeIndexed()}: the `<meta name="robots">` of the
 * layout and the `X-Robots-Tag` header of {@see SetRobotsTag} both follow it.
 */
final readonly class PageMeta
{
    /** The Open Graph locale of the languages the application is most likely to be translated into. */
    private const OG_LOCALES = [
        'fr' => 'fr_FR', 'en' => 'en_US', 'de' => 'de_DE', 'es' => 'es_ES', 'it' => 'it_IT', 'pt' => 'pt_PT', 'nl' => 'nl_NL',
        'pl' => 'pl_PL', 'sv' => 'sv_SE', 'da' => 'da_DK', 'nb' => 'nb_NO', 'fi' => 'fi_FI', 'cs' => 'cs_CZ', 'ja' => 'ja_JP',
    ];

    /**
     * @param  string  $title  The title of the page, without the name of the application.
     * @param  string  $description  A sentence or two, plain text.
     * @param  string  $canonical  The absolute address the page is known by.
     * @param  string  $locale  The language of the page.
     * @param  array<string, string>  $alternates  The same page in each language it exists in, absolute addresses by language;
     *                                             none for a page that exists in one language only.
     * @param  string|null  $default  The address for visitors whose language is not among the alternates (`x-default`).
     * @param  list<array<string, mixed>>  $jsonLd  Structured data, one schema.org object each.
     * @param  bool  $noindex  Whether the page asks not to be indexed even when the instance allows it: a page that shows the
     *                         text of another language than its own address (its canonical address is the other one).
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $locale,
        public array $alternates = [],
        public ?string $default = null,
        public array $jsonLd = [],
        public bool $noindex = false,
    ) {}

    /**
     * Whether the page asks to be indexed: the instance allows it (`app.indexable`, APP_INDEXABLE) and the page does not
     * ask otherwise.
     */
    public function mayBeIndexed(): bool
    {
        return config()->boolean('app.indexable') && ! $this->noindex;
    }

    /**
     * The Open Graph locale of the page ("fr_FR").
     */
    public function ogLocale(): string
    {
        return self::ogLocaleOf($this->locale);
    }

    /**
     * The Open Graph locales of the other languages of the page, for `og:locale:alternate`.
     *
     * @return list<string>
     */
    public function ogAlternateLocales(): array
    {
        return array_values(array_map(
            self::ogLocaleOf(...),
            array_filter(array_map(strval(...), array_keys($this->alternates)), fn (string $locale): bool => $locale !== $this->locale),
        ));
    }

    /**
     * The structured data written as the body of `<script type="application/ld+json">` elements: each object with its
     * `@context`, and encoded so that nothing in it can end the element or start another (`<`, `>`, `&` and quotes are
     * written as escapes, which JSON readers read back as the same characters).
     *
     * @return list<string>
     */
    public function jsonLdScripts(): array
    {
        return array_map(
            fn (array $data): string => json_encode(['@context' => 'https://schema.org'] + $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            $this->jsonLd,
        );
    }

    /**
     * The Open Graph locale of a language: the usual region of the language, else the language twice ("xx_XX").
     */
    private static function ogLocaleOf(string $locale): string
    {
        $locale = str_replace('-', '_', $locale);

        if (str_contains($locale, '_')) {
            return $locale;
        }

        return self::OG_LOCALES[$locale] ?? $locale.'_'.strtoupper($locale);
    }
}
