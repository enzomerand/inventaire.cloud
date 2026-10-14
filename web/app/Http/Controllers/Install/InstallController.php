<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Installer\SetupToken;
use App\Installer\WizardState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The install wizard page and its requirements check.
 */
final class InstallController extends Controller
{
    /**
     * Shows the setup token form, or the wizard once the token was entered.
     *
     * The wizard's state (current values, which steps are done) is only built
     * for an authorized session: it contains the instance's configuration.
     */
    public function show(Request $request, SetupToken $token, WizardState $wizard): View
    {
        $authorized = $token->isAuthorized($request->session());

        return view('install.index', [
            'authorized' => $authorized,
            'state' => $authorized ? $wizard->build($request) : null,
        ]);
    }

    /**
     * Runs the requirements check again, e.g. after the administrator fixed a permission.
     */
    public function requirements(WizardState $wizard): JsonResponse
    {
        return new JsonResponse(['requirements' => $wizard->requirements()]);
    }
}
