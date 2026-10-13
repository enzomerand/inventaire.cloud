<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Installer\SetupToken;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The install wizard page.
 */
final class InstallController extends Controller
{
    /**
     * Shows the setup token form, or the wizard once the token was entered.
     */
    public function show(Request $request, SetupToken $token): View
    {
        return view('install.index', [
            'authorized' => $token->isAuthorized($request->session()),
        ]);
    }
}
