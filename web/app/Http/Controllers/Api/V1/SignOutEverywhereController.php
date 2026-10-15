<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Signs the account out of every device but this one.
 */
final class SignOutEverywhereController extends Controller
{
    /**
     * Revokes the account's API tokens, renews its remember token, then signs this device in again with the new one.
     *
     * The token is what every session and "remember me" cookie of the account is tied to (see
     * {@see User::getAuthPassword()}): once it changes, the other devices are refused wherever they
     * come back, on the web pages as on the API, whether they hold a session or only the cookie. This device is
     * given a fresh session and a cookie for the new token, so it stays signed in; the session id changes with it.
     *
     * The API tokens are not tied to a session, so they would survive: a lost device may hold one, and signing out
     * everywhere is what a person does about a lost device. They are revoked first, so that a failure leaves
     * nothing half done on the side that matters.
     */
    public function __invoke(Request $request): Response
    {
        $user = $this->user($request);

        $user->tokens()->delete();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        // The provider's own way of renewing a token: it saves it without touching the account's update time.
        $guard->getProvider()->updateRememberToken($user, Str::random(60));
        $guard->login($user, remember: true);

        return response()->noContent();
    }
}
