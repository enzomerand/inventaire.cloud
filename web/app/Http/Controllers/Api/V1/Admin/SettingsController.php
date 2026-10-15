<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Auth\RequestCodeController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;

/**
 * The settings of the instance, for its administrators (the routes ask for the "admin" gate and for the web front).
 *
 * `registration_open`: whether an address that has no account can create one by signing in. Closed, only the
 * addresses that already have an account get a code (see {@see RequestCodeController}), and a code issued earlier to
 * an address without one opens nothing; accounts then come from the administrators.
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly InstanceSettings $settings) {}

    /**
     * Every setting with its current value.
     */
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->settings->all()]);
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
