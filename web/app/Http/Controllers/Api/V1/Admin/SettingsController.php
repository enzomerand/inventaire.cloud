<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Auth\RequestCodeController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Settings\InstanceSettings;
use App\Signatures\Documenso;
use Illuminate\Http\JsonResponse;

/**
 * The settings of the instance, for its administrators (the routes ask for the "admin" gate and for the web front).
 *
 * `registration_open`: whether an address that has no account can create one by signing in. Closed, only the
 * addresses that already have an account get a code (see {@see RequestCodeController}), and a code issued earlier to
 * an address without one opens nothing; accounts then come from the administrators. While it is closed, `invitation_sign_up` (on by
 * default) lets an address that a workspace invited sign up anyway, so that "closed" means "on invitation"; turned off, invitations open
 * nothing and accounts come from the administrators alone. It has no effect while registration is open.
 *
 * `share_links_enabled` (on by default): whether the owners of an inventory can share it through a secret read-only link. Turned off, no link
 * can be made (`409`, `share_links_disabled`) and every link that exists answers as if it did not; they work again when it is turned back on.
 *
 * The answer also tells, read-only, where the e-signature service (Documenso) stands: `documenso.configured` (the instance has its
 * address and API token), `documenso.webhook_secret_configured` (the webhook is open) and `documenso.webhook_url`, the address to give
 * Documenso, built from `APP_URL` (see {@see Documenso::status()}). The secrets themselves are never given, and these are set in the environment, not here.
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly InstanceSettings $settings) {}

    /**
     * Every setting with its current value, and the state of the e-signature service.
     */
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->settings->all() + ['documenso' => Documenso::status()]]);
    }

    /**
     * Changes the settings that the request carries (the others keep their value, and at least one is required: `422`
     * otherwise), and answers with all of them as they are now.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request->settings());

        return $this->show();
    }
}
