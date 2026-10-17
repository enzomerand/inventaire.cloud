<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Docs\ApiReference;
use App\Docs\OpenApi;
use App\Http\Controllers\Controller;
use App\Support\Seo\PublicPageMeta;
use Illuminate\Contracts\View\View;

/**
 * `/docs/api`: the documentation of the public API, public and rendered by the server from the OpenAPI document
 * ({@see OpenApi}), the same one `/api/v1/openapi.json` serves.
 *
 * The page exists once, at one address: the text of the document is English whatever the visitor's language, so a copy for
 * each language would only translate the labels around it. Those follow the language of the request, like every page that is not
 * a template.
 */
final class ApiDocsController extends Controller
{
    /**
     * Gets what the page shows and what its head says to search engines.
     */
    public function __construct(
        private readonly ApiReference $reference,
        private readonly PublicPageMeta $meta,
    ) {}

    /**
     * Shows the introduction, the operations and the schemas.
     */
    public function __invoke(): View
    {
        return view('docs.api', [
            'reference' => $this->reference->page(),
            'seo' => $this->meta->apiDocs(app()->getLocale()),
        ]);
    }
}
