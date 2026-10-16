<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Inventories\Templates\TemplateCatalogue;
use App\Services\LocaleResolver;
use App\Support\PublicUrls;
use Illuminate\Http\Response;
use XMLWriter;

/**
 * `/sitemap.xml`: the public pages, for the instances that let search engines index them (`app.indexable`); a 404 for
 * the others.
 *
 * It lists the home page, the gallery in every language and each template in every language it is written in. Each address
 * of a page that exists in several languages carries the `xhtml:link` alternates of all of them, itself included, and an
 * `x-default`. There is no `lastmod`: the pages are drawn from files whose date says nothing about the content.
 */
final class SitemapController extends Controller
{
    /** The namespace of the sitemap protocol. */
    private const SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** The namespace of the alternates. */
    private const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /**
     * Gets the catalogue and the resolver that knows the supported languages.
     */
    public function __construct(
        private readonly TemplateCatalogue $catalogue,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * The sitemap, as XML.
     */
    public function __invoke(): Response
    {
        abort_unless(config()->boolean('app.indexable'), 404);

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', self::SITEMAP_NAMESPACE);
        $xml->writeAttribute('xmlns:xhtml', self::XHTML_NAMESPACE);

        $this->entry($xml, PublicUrls::home(), []);

        $gallery = PublicUrls::alternates(PublicUrls::templates(...));

        foreach ($gallery as $address) {
            $this->entry($xml, $address, $gallery, PublicUrls::templates(config()->string('app.default_locale')));
        }

        foreach ($this->catalogue->all() as $template) {
            $written = array_values(array_intersect($this->locales->supported(), $template->locales()));
            $pages = array_combine($written, array_map(fn (string $locale): string => PublicUrls::template($template->slug, $locale), $written));
            $default = $pages[config()->string('app.default_locale')] ?? ($pages[$written[0] ?? ''] ?? '');

            foreach ($pages as $address) {
                $this->entry($xml, $address, $pages, $default);
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Writes the entry of a page, with the same page in the other languages.
     *
     * @param  array<string, string>  $alternates  The page in each language, by language.
     * @param  string|null  $default  The address of the page for visitors of any other language.
     */
    private function entry(XMLWriter $xml, string $address, array $alternates, ?string $default = null): void
    {
        $xml->startElement('url');
        $xml->writeElement('loc', $address);

        foreach ($alternates as $locale => $alternate) {
            $this->alternate($xml, (string) $locale, $alternate);
        }

        if ($alternates !== [] && $default !== null && $default !== '') {
            $this->alternate($xml, 'x-default', $default);
        }

        $xml->endElement();
    }

    /**
     * Writes one `xhtml:link` alternate.
     */
    private function alternate(XMLWriter $xml, string $language, string $address): void
    {
        $xml->startElement('xhtml:link');
        $xml->writeAttribute('rel', 'alternate');
        $xml->writeAttribute('hreflang', $language);
        $xml->writeAttribute('href', $address);
        $xml->endElement();
    }
}
