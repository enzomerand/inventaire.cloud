<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Inventories\Templates\TemplateCatalogue;
use App\Inventories\Templates\TemplatePresenter;
use App\Support\PublicUrls;
use Illuminate\Contracts\View\View;

/**
 * `/templates/{slug}` (and `/{locale}/templates/{slug}`): one template, public and rendered by the server: its name, its
 * summary and description, the table of its fields, a few example rows and the button that uses it.
 *
 * A slug the catalogue does not have is a 404.
 */
final class TemplatePageController extends Controller
{
    /**
     * Gets the catalogue and what turns a template into something to read.
     */
    public function __construct(
        private readonly TemplateCatalogue $catalogue,
        private readonly TemplatePresenter $presenter,
    ) {}

    /**
     * Shows the template.
     */
    public function __invoke(string $slug): View
    {
        $template = $this->catalogue->find($slug) ?? abort(404);
        $locale = app()->getLocale();

        return view('templates.show', [
            'template' => $template,
            'locale' => $locale,
            'fields' => $this->presenter->fields($template, $locale),
            'examples' => $this->presenter->examples($template, $locale),
            'localeAlternates' => PublicUrls::alternates(fn (string $alternate): string => PublicUrls::templatePath($template->slug, $alternate)),
        ]);
    }
}
