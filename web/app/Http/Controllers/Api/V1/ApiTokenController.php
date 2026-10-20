<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\ApiTokens;
use App\Auth\Exceptions\ApiTokenLimitReachedException;
use App\Auth\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreApiTokenRequest;
use App\Http\Resources\ApiTokenResource;
use App\Http\Responses\ApiError;
use App\Mail\AfterResponse;
use App\Mail\ApiTokenCreatedMail;
use App\Models\User;
use App\Support\PublicUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
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
     * The person is told by email, after the answer ({@see AfterResponse}), that a token was made: with its name, abilities and
     * expiry, where the request came from and where to revoke it, never its value. Only a token that was made is announced, once; a
     * mail that cannot be sent is logged and does not undo anything.
     *
     * `422` for a mistake in the request; `409` (`token_limit_reached`, with the `limit`) when the person already has as many
     * tokens as they may; `429` beyond the pace of creations.
     */
    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        $user = $this->user($request);

        try {
            $token = $this->tokens->create($user, $request->tokenName(), $request->abilities(), $request->lifetimeDays());
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

        // Not inside `create()`, which runs again when the database picks it as the loser of a deadlock: the mail would go twice.
        $this->announce($request, $user, $token->accessToken);

        return (new ApiTokenResource($token->accessToken, $secret))->response()
            ->setStatusCode(201)
            // The only answer that holds the value of a token: nothing is to keep a copy of it.
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Tells the person, after the answer, that a token was made on their account, in the language of the account.
     *
     * What the mail needs from the request (the address and the user agent) is read now, as strings: the request is gone when it is
     * sent. It is given what describes the token, never its value ({@see ApiTokenCreatedMail}).
     */
    private function announce(Request $request, User $user, PersonalAccessToken $token): void
    {
        $email = $user->email;
        $locale = $user->locale ?? app()->getLocale();
        $mail = new ApiTokenCreatedMail(
            name: $user->name,
            tokenName: $token->name,
            abilities: array_values(array_intersect(TokenAbility::values(), $token->abilities ?? [])),
            createdAt: $token->created_at ?? now(),
            expiresAt: $token->expires_at,
            ipAddress: $request->ip() ?? '',
            userAgent: $request->userAgent() ?? '',
            accountUrl: PublicUrls::to(route('app.account', absolute: false)),
        );

        AfterResponse::run('an API token notice', function () use ($email, $locale, $mail): void {
            Mail::to($email)->locale($locale)->send($mail);
        });
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
