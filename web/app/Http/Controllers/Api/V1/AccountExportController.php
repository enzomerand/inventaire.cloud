<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\AccountExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hands the account's data over as a file (GDPR: right of access and portability).
 */
final class AccountExportController extends Controller
{
    /**
     * The export of the signed-in account, as a JSON file to download (`inventaire-export-YYYY-MM-DD.json`).
     *
     * The route is throttled; the answer is never cached, since it holds personal data.
     */
    public function __invoke(Request $request, AccountExport $export): JsonResponse
    {
        return new JsonResponse(
            $export->for($this->user($request)),
            200,
            [
                'Content-Disposition' => 'attachment; filename=inventaire-export-'.now()->format('Y-m-d').'.json',
                'Cache-Control' => 'no-store, private',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
