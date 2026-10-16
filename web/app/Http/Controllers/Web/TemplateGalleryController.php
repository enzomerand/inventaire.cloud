<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\UseUrlLocale;
use App\Inventories\Templates\TemplateCatalogue;
use App\Support\PublicUrls;
use App\Support\Seo\PublicPageMeta;
use Illuminate\Contracts\View\View;

/**
 * `/templates` (and `/{locale}/templates`): the gallery of the templates by category, public and rendered by the server.
 *
 * The language is the one of the address (see {@see UseUrlLocale}).
 */
final class TemplateGalleryController extends Controller
{
    /**
     * Gets the catalogue and what the head of the page says to search engines.
     */
    public function __construct(
        private readonly TemplateCatalogue $catalogue,
        private readonly PublicPageMeta $meta,
    ) {}

    /**
     * Shows the templates, each category under its heading.
     */
    public function __invoke(): View
    {
        $locale = app()->getLocale();
        $groups = $this->catalogue->grouped($locale);

        return view('templates.index', [
            'groups' => $groups,
            'locale' => $locale,
            'seo' => $this->meta->gallery($groups, $locale),
            'localeAlternates' => PublicUrls::alternates(PublicUrls::templatesPath(...)),
        ]);
    }
}
