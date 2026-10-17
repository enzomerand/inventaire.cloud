<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Signatures\Documenso;
use Illuminate\Contracts\View\View;

/**
 * `/app/admin/settings`: the choices that apply to the whole instance, for its administrators (the route asks for the "admin" gate).
 *
 * The page hands the markup where the e-signature service (Documenso) stands, read each time from the environment of the instance (a
 * page cannot be built when the routes are registered, or a cached route list would keep the answer of that day).
 */
final class AdminSettingsPageController extends Controller
{
    /**
     * Shows the settings page, with the state of Documenso.
     */
    public function __invoke(): View
    {
        return view('app.admin.settings', ['documenso' => Documenso::status()]);
    }
}
