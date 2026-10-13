<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use App\Services\LocaleResolver;
use Illuminate\Http\Response;

/**
 * Remembers the interface language chosen with the language switcher.
 */
class UpdateLocaleController extends Controller
{
    /**
     * Attaches the long-lived locale cookie that {@see LocaleResolver} reads on
     * the following requests. Nothing to return, so the front end simply reloads.
     */
    public function __invoke(UpdateLocaleRequest $request, LocaleResolver $resolver): Response
    {
        return response()->noContent()->withCookie($resolver->cookie($request->string('locale')->toString()));
    }
}
