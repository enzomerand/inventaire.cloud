<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\LocaleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user: who they are, and what they can change about themselves.
 */
final class MeController extends Controller
{
    /**
     * The current user. An unauthenticated client never gets here: it receives a 401 in JSON.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($this->user($request));
    }

    /**
     * Changes the name and/or the language of the account.
     *
     * Choosing a language also puts it in the `locale` cookie, which is what the language of a request is decided
     * from (see {@see LocaleResolver}): the interface is in the new language from the next page on, as it is when the
     * language switcher of the header is used.
     */
    public function update(UpdateProfileRequest $request, LocaleResolver $locales): JsonResponse
    {
        $user = $this->user($request);

        if ($request->name() !== null) {
            $user->name = $request->name();
        }

        if ($request->locale() !== null) {
            $user->locale = $request->locale();
        }

        $user->save();

        $response = (new UserResource($user))->response();

        return $request->locale() === null ? $response : $response->withCookie($locales->cookie($request->locale()));
    }
}
