<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\UseUrlLocale;
use App\Inventories\Templates\TemplateCatalogue;
use App\Support\PublicUrls;
use Illuminate\Contracts\View\View;

/**
 * `/templates` (and `/{locale}/templates`): the gallery of the templates by category, public and rendered by the server.
 *
 * The language is the one of the address (see {@see UseUrlLocale}).
 */
final class TemplateGalleryController extends Controller
{
    /**
     * Gets the catalogue.
     */
    public function __construct(private readonly TemplateCatalogue $catalogue) {}

    /**
     * Shows the templates, each category under its heading.
     */
    public function __invoke(): View
    {
        $locale = app()->getLocale();

        return view('templates.index', [
            'groups' => $this->catalogue->grouped($locale),
            'locale' => $locale,
            'localeAlternates' => PublicUrls::alternates(PublicUrls::templatesPath(...)),
        ]);
    }
}
