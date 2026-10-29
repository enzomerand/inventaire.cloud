<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Inventories\Templates\Template;
use App\Landing\Faq;
use App\Landing\Pitch;
use App\Landing\PricingPlans;
use App\Services\LocaleResolver;
use App\Support\PublicUrls;

/**
 * Builds the {@see PageMeta} of the public pages: the home page, the pricing page, the documentation of the API, the gallery of templates and the
 * page of each template.
 *
 * A template is offered in the languages it is written in: the other languages of the application show it with the
 * texts of the default language (the language switcher links to them), but they are not listed as alternates, which
 * would announce a translation that is not there. Such a page is the same page as the one in the language of its texts, so
 * it asks not to be indexed, its canonical address is the one of that language, and it says it is written in it
 * (`inLanguage`, `og:locale`): the engines keep one page, not one copy for each language that has no text of its own.
 */
final class PublicPageMeta
{
    /**
     * Gets the resolver that knows the supported languages.
     */
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * The home page (it exists in the language of the request only: the language switcher stores the choice), with the application and
     * its questions as structured data.
     *
     * @param  PricingPlans|null  $plans  The plans of the instance; null when it sells none, and then nothing about plans or prices is said.
     */
    public function home(string $locale, ?PricingPlans $plans = null): PageMeta
    {
        return new PageMeta(
            title: __('Free online inventory software'),
            description: Pitch::description($plans),
            canonical: PublicUrls::home(),
            locale: $locale,
            jsonLd: [$this->site(), $this->application($plans, $locale), $this->faqPage(Faq::home($plans))],
        );
    }

    /**
     * The pricing page in a language: the plans, the billing questions and the breadcrumb as structured data. It exists only on an
     * instance that sells a plan.
     */
    public function pricing(PricingPlans $plans, string $locale): PageMeta
    {
        $canonical = PublicUrls::pricing($locale);
        $title = __('Pricing: Free and Pro plans');
        $description = __('Compare the Free and Pro plans: inventories, items, storage, members and share links. Pro from :price a month, cancel anytime.', ['price' => $plans->monthlyPrice()]);

        return new PageMeta(
            title: $title,
            description: $description,
            canonical: $canonical,
            locale: $locale,
            alternates: PublicUrls::alternates(PublicUrls::pricing(...)),
            default: PublicUrls::pricing(config()->string('app.default_locale')),
            jsonLd: [
                [
                    '@type' => 'WebPage',
                    'name' => $title,
                    'description' => $description,
                    'url' => $canonical,
                    'inLanguage' => $locale,
                    'isPartOf' => $this->site(),
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home'), 'item' => PublicUrls::home()],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => __('Pricing'), 'item' => $canonical],
                    ],
                ],
                $this->application($plans, $locale),
                $this->faqPage(Faq::billing($plans)),
            ],
        );
    }

    /**
     * The documentation of the API (it exists at one address, in the language of the request).
     */
    public function apiDocs(string $locale): PageMeta
    {
        $canonical = PublicUrls::apiDocs();
        $title = __('API documentation');
        $description = __('Reference of the JSON API: personal access tokens, permissions, rate limits, errors, and every operation with its parameters, bodies and responses.');

        return new PageMeta(
            title: $title,
            description: $description,
            canonical: $canonical,
            locale: $locale,
            jsonLd: [
                [
                    '@type' => 'WebPage',
                    'name' => $title,
                    'description' => $description,
                    'url' => $canonical,
                    'inLanguage' => $locale,
                    'isPartOf' => $this->site(),
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home'), 'item' => PublicUrls::home()],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $canonical],
                    ],
                ],
            ],
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
        // The language of the texts the page shows: its own language when the template is written in it, else the one it falls back on.
        $pageLocale = $template->localeFor($locale);
        $canonical = PublicUrls::template($template->slug, $pageLocale);
        $name = $template->name($locale);
        $title = __(':name: free template', ['name' => $name]);
        $summary = $template->summary($locale);

        $written = array_values(array_intersect($this->locales->supported(), $template->locales()));
        $default = in_array(config()->string('app.default_locale'), $written, true) ? config()->string('app.default_locale') : ($written[0] ?? $pageLocale);

        return new PageMeta(
            title: $title,
            description: $summary,
            canonical: $canonical,
            locale: $pageLocale,
            alternates: array_combine($written, array_map(fn (string $alternate): string => PublicUrls::template($template->slug, $alternate), $written)),
            default: PublicUrls::template($template->slug, $default),
            jsonLd: [
                [
                    '@type' => 'WebPage',
                    'name' => $title,
                    'description' => $summary,
                    'url' => $canonical,
                    'inLanguage' => $pageLocale,
                    'about' => ['@type' => 'Thing', 'name' => $name, 'description' => $summary],
                    'isPartOf' => $this->site(),
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home'), 'item' => PublicUrls::home()],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => __('Inventory templates'), 'item' => PublicUrls::templates($pageLocale)],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $canonical],
                    ],
                ],
            ],
            noindex: $pageLocale !== $locale,
        );
    }

    /**
     * The application, as structured data: free to use and open source, and, on an instance that sells a plan, what each plan costs.
     *
     * @param  PricingPlans|null  $plans  The plans of the instance; null when it sells none: the one offer is then the free one.
     * @return array<string, mixed>
     */
    private function application(?PricingPlans $plans, string $locale): array
    {
        $currency = strtoupper($plans->currency ?? config()->string('billing.currency'));
        $offers = [['@type' => 'Offer', 'price' => '0', 'priceCurrency' => $currency] + ($plans === null ? [] : ['name' => __('Free')])];

        if ($plans !== null) {
            $periods = ['P1M' => $plans->month, 'P1Y' => $plans->year];

            foreach ($periods as $duration => $cents) {
                if ($cents === null) {
                    continue;
                }

                $offers[] = [
                    '@type' => 'Offer',
                    'name' => __('Pro'),
                    'price' => PricingPlans::decimal($cents),
                    'priceCurrency' => $currency,
                    'priceSpecification' => [
                        '@type' => 'UnitPriceSpecification',
                        'price' => PricingPlans::decimal($cents),
                        'priceCurrency' => $currency,
                        'billingDuration' => $duration,
                    ],
                ];
            }
        }

        return [
            '@type' => 'SoftwareApplication',
            'name' => config()->string('app.name'),
            'url' => PublicUrls::home(),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'inLanguage' => $locale,
            'isAccessibleForFree' => true,
            'license' => 'https://www.gnu.org/licenses/agpl-3.0.html',
            'sameAs' => [rtrim(config()->string('app.repository_url'), '/')],
            'offers' => count($offers) === 1 ? $offers[0] : $offers,
        ];
    }

    /**
     * The questions of a page as structured data, the very texts the page shows.
     *
     * @param  list<array{question: string, answer: string}>  $questions
     * @return array<string, mixed>
     */
    private function faqPage(array $questions): array
    {
        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $entry): array => [
                '@type' => 'Question',
                'name' => $entry['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $entry['answer']],
            ], $questions),
        ];
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
