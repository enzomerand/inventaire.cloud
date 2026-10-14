<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the install wizard once the instance is installed.
 *
 * A 404 (rather than a redirect or a 403) so a finished instance does not even
 * reveal that an installer ever existed at that URL.
 */
final class AbortIfInstalled
{
    public function __construct(private readonly InstallationState $state) {}

    /**
     * Answers 404 when the installation is complete.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->state->isInstalled(), 404);

        return $next($request);
    }
}
