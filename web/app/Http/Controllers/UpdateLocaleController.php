<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use App\Models\User;
use App\Services\LocaleResolver;
use Illuminate\Http\Response;

/**
 * Remembers the interface language chosen with the language switcher.
 */
class UpdateLocaleController extends Controller
{
    /**
     * Attaches the long-lived locale cookie that {@see LocaleResolver} reads on
     * the following requests and, for a signed-in user, saves the choice on the
     * account so that it follows them to their next device. Nothing to return,
     * so the front end simply reloads.
     */
    public function __invoke(UpdateLocaleRequest $request, LocaleResolver $resolver): Response
    {
        $locale = $request->string('locale')->toString();
        $user = $request->user();

        if ($user instanceof User) {
            $user->update(['locale' => $locale]);
        }

        return response()->noContent()->withCookie($resolver->cookie($locale));
    }
}
