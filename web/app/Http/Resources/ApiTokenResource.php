<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Auth\TokenAbility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An API token as the API gives it: what it is called, what it may do and when it was made, last used and expires.
 *
 * ```
 * {"id", "name", "abilities", "created_at", "last_used_at", "expires_at"}
 * ```
 *
 * `abilities` lists the values of {@see TokenAbility} (a token made with `*` has all of them). `last_used_at` and `expires_at` are
 * null for a token that was never used and one that never expires. The value of the token is not here: it is given once, with
 * `plain_text_token`, in the answer that makes the token, and nobody can read it back.
 *
 * @property PersonalAccessToken $resource
 */
final class ApiTokenResource extends JsonResource
{
    /**
     * Remembers the token and, for the answer that makes it, its value.
     *
     * @param  PersonalAccessToken  $resource
     * @param  string|null  $plainTextToken  The value of a token that has just been made, else null.
     */
    public function __construct($resource, private readonly ?string $plainTextToken = null)
    {
        parent::__construct($resource);
    }

    /**
     * The token as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $this->resource;
        $abilities = $token->abilities ?? [];

        return [
            'id' => $token->getKey(),
            'name' => $token->name,
            'abilities' => in_array('*', $abilities, true) ? TokenAbility::values() : array_values(array_intersect(TokenAbility::values(), $abilities)),
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ] + ($this->plainTextToken === null ? [] : ['plain_text_token' => $this->plainTextToken]);
    }
}
