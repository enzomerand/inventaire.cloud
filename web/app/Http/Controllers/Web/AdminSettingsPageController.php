<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\PhotoEntry\PhotoEntry;
use App\Signatures\Documenso;
use Illuminate\Contracts\View\View;

/**
 * `/app/admin/settings`: the choices that apply to the whole instance, for its administrators (the route asks for the "admin" gate).
 *
 * The page hands the markup where the e-signature service (Documenso) stands and whether there is an Anthropic API key (the photo entry
 * needs one), both read each time from the environment of the instance (a page cannot be built when the routes are registered, or a cached
 * route list would keep the answer of that day). Only whether the key is there is handed over, never the key.
 */
final class AdminSettingsPageController extends Controller
{
    /**
     * Shows the settings page, with the state of Documenso and whether an Anthropic API key is configured.
     */
    public function __invoke(PhotoEntry $photoEntry): View
    {
        return view('app.admin.settings', ['documenso' => Documenso::status(), 'photoEntryKey' => $photoEntry->hasKey()]);
    }
}
