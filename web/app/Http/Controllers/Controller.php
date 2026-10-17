<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Base class of the web controllers; shared behaviour goes here.
 */
abstract class Controller
{
    /**
     * The signed-in user of a request that went through an authentication middleware.
     *
     * @throws AuthenticationException When nobody is signed in, which the middleware makes unreachable.
     */
    protected function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * The API token the request is made with, null for the web front's session (and for a request nobody is signed in for, which the
     * middleware of the routes that ask for this makes unreachable).
     */
    protected function apiToken(Request $request): ?PersonalAccessToken
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
