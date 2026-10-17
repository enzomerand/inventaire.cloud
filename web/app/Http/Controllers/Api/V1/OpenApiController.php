<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Docs\OpenApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/openapi.json`: the OpenAPI 3.1 description of the API, public (it is what anyone needs before asking for a
 * token) and the same for everybody, so a browser may keep it for an hour. A shared cache may not: the response of a request
 * the web front makes carries the session cookie, hence `private`.
 *
 * The `servers` entry is the address of the instance built from `APP_URL` (see {@see OpenApi::document()}).
 */
final class OpenApiController extends Controller
{
    /** How long a browser may keep the document, in seconds. */
    private const MAX_AGE = 3600;

    /**
     * Gets the specification.
     */
    public function __construct(private readonly OpenApi $openApi) {}

    /**
     * The document, as JSON with its slashes and accents left as they are (it is read by people as much as by programs).
     */
    public function __invoke(): JsonResponse
    {
        return response()
            ->json($this->openApi->document(), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ->header('Cache-Control', 'private, max-age='.self::MAX_AGE);
    }
}
