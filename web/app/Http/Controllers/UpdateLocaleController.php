<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use App\Services\LocaleResolver;
use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Http\Response;

/**
 * Remembers the interface language chosen with the language switcher.
 */
class UpdateLocaleController extends Controller
{
    /**
     * Queues the long-lived locale cookie that {@see LocaleResolver} reads on the
     * following requests. Nothing to return, so the front end simply reloads.
     */
    public function __invoke(UpdateLocaleRequest $request, LocaleResolver $resolver, QueueingFactory $cookies): Response
    {
        $cookies->queue($resolver->cookie($request->string('locale')->toString()));

        return response()->noContent();
    }
}
