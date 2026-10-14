<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Http\Requests\Install\TokenRequest;
use App\Installer\SetupToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Unlocks the wizard for the current browser session when the right setup
 * token is submitted.
 */
final class TokenController extends Controller
{
    /**
     * Verifies the token and authorizes the session.
     *
     * @throws ValidationException When the token is wrong.
     */
    public function __invoke(TokenRequest $request, SetupToken $token): JsonResponse
    {
        if (! $token->verify($request->string('token')->toString())) {
            throw ValidationException::withMessages(['token' => __('This setup token is not valid.')]);
        }

        $token->authorize($request->session());

        return new JsonResponse(['authorized' => true]);
    }
}
