<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Docs\OpenApi;
use App\Docs\RenderedReference;
use App\Http\Controllers\Controller;
use App\Support\Seo\PublicPageMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/docs/api`: the documentation of the public API, public and rendered by the server from the OpenAPI document
 * ({@see OpenApi}), the same one `/api/v1/openapi.json` serves.
 *
 * The page exists once, at one address: the text of the document is English whatever the visitor's language, so a copy for
 * each language would only translate the labels around it. Those follow the language of the request, like every page that is not
 * a template.
 *
 * The long part of the page is drawn once for each language and kept in the cache ({@see RenderedReference}), so that a request
 * only draws the frame around it. The page is about 700 KB, and is revalidated rather than sent again: it answers with an `ETag` and a
 * `304` to a request that holds the current one. The tag is the one of the whole page, since the frame around the text is not the same
 * for everybody (a signed-in person has their menu, the session has its own CSRF token and may hold a notice), and a `304` must
 * not hand a person the page of somebody else, or a token that is not theirs. `Cache-Control: private, no-cache`: the answer may carry the
 * session cookie, so a shared cache must not keep it, and a browser asks before reusing its copy.
 */
final class ApiDocsController extends Controller
{
    /**
     * Gets what the page shows and what its head says to search engines.
     */
    public function __construct(
        private readonly RenderedReference $reference,
        private readonly PublicPageMeta $meta,
    ) {}

    /**
     * Shows the introduction, the operations and the schemas, or says that the copy the visitor holds is the current one.
     */
    public function __invoke(Request $request): Response
    {
        // Answered as a view, which is how the middleware that tells search engines what to index recognises a page.
        $response = response()->view('docs.api', [
            'reference' => $this->reference->html(app()->getLocale()),
            'seo' => $this->meta->apiDocs(app()->getLocale()),
        ]);
        $response->setEtag(hash('xxh128', (string) $response->getContent()), true);
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
