<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\Response;

/**
 * Remembers the interface language chosen with the language switcher.
 */
class UpdateLocaleController extends Controller
{
    /**
     * Stores the locale in the session; the next request is rendered with it by
     * {@see SetLocale}. Nothing to return, so the front end simply reloads.
     */
    public function __invoke(UpdateLocaleRequest $request): Response
    {
        $request->session()->put(SetLocale::SESSION_KEY, $request->validated('locale'));

        return response()->noContent();
    }
}
