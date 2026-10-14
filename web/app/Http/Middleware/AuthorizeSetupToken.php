<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\SetupToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the wizard's step endpoints with the one-time setup token.
 *
 * Only browsers whose session entered the current token get through; the
 * answer is JSON because these endpoints are only called by the wizard's
 * script, which reloads the page (and so shows the token form) on the
 * `setup_token_required` code.
 */
final class AuthorizeSetupToken
{
    public function __construct(private readonly SetupToken $token) {}

    /**
     * Rejects requests from sessions that have not entered the token.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->token->isAuthorized($request->session())) {
            return new JsonResponse([
                'message' => __('Enter the setup token to continue.'),
                'code' => 'setup_token_required',
            ], 403);
        }

        return $next($request);
    }
}
