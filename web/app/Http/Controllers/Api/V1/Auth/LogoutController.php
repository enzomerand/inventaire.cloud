<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Signs the person out of this browser.
 */
final class LogoutController extends Controller
{
    /**
     * Ends the session of this device, forgets its "remember me" cookie and
     * issues a new CSRF token.
     *
     * The "remember me" token is shared by every device of the account, so it
     * is deliberately not renewed: the other devices stay signed in. Signing
     * out of all of them at once is a separate action (it renews the token).
     */
    public function __invoke(Request $request): Response
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $guard->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
