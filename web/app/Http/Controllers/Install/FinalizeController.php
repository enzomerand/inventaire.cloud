<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationFinalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The last wizard action: creates the tables and switches the instance on.
 */
final class FinalizeController extends Controller
{
    /**
     * Finalizes the installation and tells the page where to go next.
     *
     * @throws ValidationException When the installation cannot be completed (the reason is shown on the page).
     */
    public function __invoke(InstallationFinalizer $finalizer): JsonResponse
    {
        try {
            $redirect = $finalizer->finalize();
        } catch (InstallationException $exception) {
            throw ValidationException::withMessages(['general' => $exception->userMessage()]);
        }

        return new JsonResponse(['redirect' => $redirect]);
    }
}
