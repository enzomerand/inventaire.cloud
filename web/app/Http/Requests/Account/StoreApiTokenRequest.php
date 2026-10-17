<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Auth\ApiTokens;
use App\Auth\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a person gives to make an API token: a name, the abilities it has and how long it works.
 *
 * - `name`: required, trimmed, at most 80 characters;
 * - `abilities`: one to three different values of {@see TokenAbility};
 * - `expires_in_days`: 7, 30, 90 or 365; null for a token that never expires; 90 when it is left out.
 */
final class StoreApiTokenRequest extends FormRequest
{
    /** Longest name, in characters. */
    public const NAME_MAX_LENGTH = 80;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'abilities' => ['required', 'array', 'min:1', 'max:'.count(TokenAbility::cases())],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(TokenAbility::values())],
            'expires_in_days' => ['nullable', 'integer', Rule::in(ApiTokens::LIFETIMES)],
        ];
    }

    /**
     * The validated name, already trimmed.
     */
    public function tokenName(): string
    {
        return $this->string('name')->toString();
    }

    /**
     * The abilities asked for, each once, in the order of {@see TokenAbility}.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        $asked = array_map('strval', array_values((array) $this->input('abilities')));

        return array_values(array_intersect(TokenAbility::values(), $asked));
    }

    /**
     * Days the token works for: the number asked for, null for ever (`null` sent), or the usual 90 when the field is left out.
     */
    public function lifetimeDays(): ?int
    {
        if (! $this->exists('expires_in_days')) {
            return ApiTokens::DEFAULT_LIFETIME_DAYS;
        }

        $days = $this->input('expires_in_days');

        return $days === null ? null : (int) $days;
    }

    /**
     * Trims the name before it is validated, so that a name made of spaces counts as missing and one with spaces around it is
     * stored without them. It also removes the invisible characters (non-breaking and zero-width spaces) a paste can bring along.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $name) ?? $name]);
        }
    }
}
