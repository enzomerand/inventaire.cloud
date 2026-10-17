<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\ApiTokens;
use App\Auth\Exceptions\ApiTokenLimitReachedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreApiTokenRequest;
use App\Http\Resources\ApiTokenResource;
use App\Http\Responses\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The API tokens of the signed-in person: list them, make one, revoke one.
 *
 * For the web front only (the routes refuse a token: a token must not be able to make others, or to revoke the one that is
 * looking at it, whatever its abilities). A token is its owner's alone: another person's is a `404`, like one that does not exist.
 */
final class ApiTokenController extends Controller
{
    /**
     * Gets the service that makes and revokes tokens.
     */
    public function __construct(private readonly ApiTokens $tokens) {}

    /**
     * The tokens of the signed-in person that did not expire, the newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Mapped here, one resource at a time: `collection()` would pass each key as the second argument, which is the value of a token.
        return ApiTokenResource::collection($this->tokens->active($this->user($request))->map(
            fn (PersonalAccessToken $token): ApiTokenResource => new ApiTokenResource($token),
        ));
    }

    /**
     * Makes a token (`201`). Its value, `plain_text_token`, is in this answer and nowhere else.
     *
     * `422` for a mistake in the request; `409` (`token_limit_reached`, with the `limit`) when the person already has as many
     * tokens as they may; `429` beyond the pace of creations.
     */
    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        try {
            $token = $this->tokens->create($this->user($request), $request->tokenName(), $request->abilities(), $request->lifetimeDays());
        } catch (ApiTokenLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('You can have :count API token at a time: revoke one, or wait for one to expire.|You can have :count API tokens at a time: revoke one, or wait for one to expire.', $limit->limit),
                ApiTokenLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        }

        // The value is "<id>|<secret>" for Sanctum, which accepts the secret alone: the secret is what carries the prefix, and
        // is what the person copies.
        $secret = Str::after($token->plainTextToken, '|');

        return (new ApiTokenResource($token->accessToken, $secret))->response()
            ->setStatusCode(201)
            // The only answer that holds the value of a token: nothing is to keep a copy of it.
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Revokes a token of the signed-in person (`204`): it stops working at once.
     */
    public function destroy(Request $request, string $token): Response
    {
        abort_unless($this->tokens->revoke($this->user($request), $token), 404);

        return response()->noContent();
    }
}
