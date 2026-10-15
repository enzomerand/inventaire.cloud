<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * Gives the session of every sign-in the fingerprint of the account, whichever way the account was signed in.
 *
 * `SessionGuard::login()` stores the fingerprint itself ({@see SessionFingerprint}), but the guard also signs somebody
 * in when it finds a valid "remember me" cookie and no session (the session expired, or the browser was closed), and
 * then it opens a session for the account *without* one. The framework's `AuthenticateSession` middleware used to
 * fill that gap, but it only runs on the pages of the signed-in space and on the API: a visitor whose cookie signs
 * them in on a public page (the home page looks the visitor up, the login page sends a signed-in visitor on) would
 * be left with a session that has no fingerprint, and their next click into the signed-in space would sign them out.
 *
 * The guard fires `Login` for the cookie as well, so storing the fingerprint here covers every route. It is the
 * fingerprint the framework would store (the HMAC of {@see User::getAuthPassword()}), and it is the
 * account's *current* one, which is right because the cookie has just been checked against it: the guard only
 * returns an account whose remember token is the cookie's, so a cookie from before "sign out everywhere" signs
 * nobody in, fires nothing, and gets no fingerprint.
 */
final class StoreSessionFingerprint
{
    /**
     * Stores the fingerprint of the account that has just been signed in, in the session of the guard that did it.
     */
    public function handle(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        if (! $guard instanceof SessionGuard) {
            return;
        }

        $fingerprint = $event->user->getAuthPassword();

        // An account without a fingerprint (no remember token) has nothing to compare: the framework stores none either.
        if ($fingerprint === '') {
            return;
        }

        $guard->getSession()->put(SessionFingerprint::key($event->guard), $guard->hashPasswordForCookie($fingerprint));
    }
}
