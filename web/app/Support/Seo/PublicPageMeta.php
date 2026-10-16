<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Inventories\Templates\Template;
use App\Services\LocaleResolver;
use App\Support\PublicUrls;

/**
 * Builds the {@see PageMeta} of the public pages: the home page, the gallery of templates and the page of each template.
 *
 * A template is offered in the languages it is written in: the other languages of the application show it with the
 * texts of the default language (the language switcher links to them), but they are not listed as alternates, which
 * would announce a translation that is not there.
 */
final class PublicPageMeta
{
    /**
     * Gets the resolver that knows the supported languages.
     */
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * The home page (it exists in the language of the request only: the language switcher stores the choice).
     */
    public function home(string $locale): PageMeta
    {
        return new PageMeta(
            title: __('Inventories, simple and free'),
            description: __('A free inventory tool for professionals and individuals.'),
            canonical: PublicUrls::home(),
            locale: $locale,
        );
    }

    /**
     * The gallery of templates in a language, with its list of templates as structured data.
     *
     * @param  array<string, list<Template>>  $groups  The templates by category.
     */
    public function gallery(array $groups, string $locale): PageMeta
    {
        $canonical = PublicUrls::templates($locale);
        $items = [];
        $position = 0;

        foreach ($groups as $templates) {
            foreach ($templates as $template) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => ++$position,
                    'name' => $template->name($locale),
                    'url' => PublicUrls::template($template->slug, $locale),
                ];
            }
        }

        $title = __('Free inventory templates');
        $description = __('Free inventory templates for home, IT equipment, stock, collections and more. Pick one and start in a minute.');

        return new PageMeta(
            title: $title,
            description: $description,
            canonical: $canonical,
            locale: $locale,
            alternates: PublicUrls::alternates(PublicUrls::templates(...)),
            default: PublicUrls::templates(config()->string('app.default_locale')),
            jsonLd: [[
                '@type' => 'CollectionPage',
                'name' => $title,
                'description' => $description,
                'url' => $canonical,
                'inLanguage' => $locale,
                'isPartOf' => $this->site(),
                'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($items), 'itemListElement' => $items],
            ]],
        );
    }

    /**
     * The page of a template in a language, with the page and its breadcrumb as structured data.
     */
    public function template(Template $template, string $locale): PageMeta
    {
        $canonical = PublicUrls::template($template->slug, $locale);
        $name = $template->name($locale);
        $title = __(':name: free inventory template', ['name' => $name]);
        $summary = $template->summary($locale);

        $written = array_values(array_intersect($this->locales->supported(), $template->locales()));
        $default = in_array(config()->string('app.default_locale'), $written, true) ? config()->string('app.default_locale') : ($written[0] ?? $locale);

        return new PageMeta(
            title: $title,
            description: $summary,
            canonical: $canonical,
            locale: $locale,
            alternates: array_combine($written, array_map(fn (string $alternate): string => PublicUrls::template($template->slug, $alternate), $written)),
            default: PublicUrls::template($template->slug, $default),
            jsonLd: [
                [
                    '@type' => 'WebPage',
                    'name' => $title,
                    'description' => $summary,
                    'url' => $canonical,
                    'inLanguage' => $locale,
                    'about' => ['@type' => 'Thing', 'name' => $name, 'description' => $summary],
                    'isPartOf' => $this->site(),
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home'), 'item' => PublicUrls::home()],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => __('Inventory templates'), 'item' => PublicUrls::templates($locale)],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $canonical],
                    ],
                ],
            ],
        );
    }

    /**
     * The web site the pages belong to, as structured data.
     *
     * @return array<string, string>
     */
    private function site(): array
    {
        return ['@type' => 'WebSite', 'name' => config()->string('app.name'), 'url' => PublicUrls::home()];
    }
}
