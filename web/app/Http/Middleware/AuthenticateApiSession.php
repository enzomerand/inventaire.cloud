<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionFingerprint;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession as SanctumAuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out the sessions of an account that renewed its credentials ("sign out everywhere"), on the API.
 *
 * It is Sanctum's own middleware for the requests of the web front (configured in `config/sanctum.php`), which
 * compares the fingerprint stored in the session with the account's current one ({@see User::getAuthPassword()}),
 * with one difference: a signed-in session that carries no fingerprint is refused instead of being given the current
 * one (see {@see SessionFingerprint}). The web pages have the same check ({@see AuthenticateSession}).
 */
final class AuthenticateApiSession extends SanctumAuthenticateSession
{
    /**
     * Refuses a signed-in session without a fingerprint, then checks the fingerprint of the others.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $lacking = Collection::make(Arr::wrap(config('sanctum.guard')))
            ->mapWithKeys(fn ($name): array => [(string) $name => $this->auth->guard(is_string($name) ? $name : null)])
            ->filter(fn ($guard, string $name): bool => $guard instanceof SessionGuard && SessionFingerprint::isMissing($request, $guard, $name));

        if ($lacking->isNotEmpty()) {
            $lacking->each->logoutCurrentDevice();

            $request->session()->flush();

            throw new AuthenticationException('Unauthenticated.', [...$lacking->keys()->all(), 'sanctum']);
        }

        return parent::handle($request, $next);
    }
}
