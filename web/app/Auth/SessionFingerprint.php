<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;

/**
 * Tells whether a session that holds a signed-in account lacks the fingerprint it must carry.
 *
 * A session stores the fingerprint of its account when it is opened ({@see User::getAuthPassword()}; the framework's
 * `SessionGuard::login()` does it), and `AuthenticateSession` compares it with the account's current one at each
 * request, to end the sessions that "sign out everywhere" has revoked. The framework's middleware, though, gives a
 * fingerprint to a session that has none and lets it through: a session that was opened before fingerprints existed
 * (or lost its fingerprint any other way) would adopt the *new* one and survive the very revocation that was meant
 * to end it. Both of the application's middleware ({@see Http\Middleware\AuthenticateSession} for the web pages and
 * {@see Http\Middleware\AuthenticateApiSession} for the API) therefore refuse such a session instead.
 *
 * Every sign-in leaves a fingerprint, on every route: the guard's own login does, and a "remember me" cookie that
 * signs somebody in on a public page (where neither middleware runs) is given one by {@see StoreSessionFingerprint}.
 */
final class SessionFingerprint
{
    /**
     * Whether the session of this request signed an account in and carries no fingerprint for it.
     *
     * Not concerned: a request that is not signed in; an account with no fingerprint (no remember token, so there is
     * nothing to compare); a request signed in by the "remember me" cookie, whose own copy of the fingerprint the
     * guard has just checked, and which is given a session of its own (and its fingerprint, see
     * {@see StoreSessionFingerprint}) at that moment; and a
     * guard that was set by the code, as `actingAs()` does, without the session ever signing anybody in.
     *
     * @param  string  $name  The name of the guard, as the fingerprint's key in the session spells it.
     */
    public static function isMissing(Request $request, SessionGuard $guard, string $name): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $user = $guard->user();

        if ($user === null || $user->getAuthPassword() === '' || $guard->viaRemember()) {
            return false;
        }

        $session = $request->session();

        return $session->has($guard->getName()) && ! $session->has(self::key($name));
    }

    /**
     * The key under which a session stores the fingerprint for a guard (the framework's own).
     */
    public static function key(string $guard): string
    {
        return 'password_hash_'.$guard;
    }
}
