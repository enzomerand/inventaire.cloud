<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

/**
 * Describes the signed-in user: what a client calls to learn whether it is signed in, and as whom.
 */
final class MeController extends Controller
{
    /**
     * The current user. An unauthenticated client never gets here: it receives a 401 in JSON.
     */
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
