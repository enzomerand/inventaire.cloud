<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Auth\ApiTokens;
use App\Auth\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What a person gives to make an API token: a name, the abilities it has and how long it works.
 *
 * - `name`: required, trimmed, at most 80 characters;
 * - `abilities`: one to three different values of {@see TokenAbility};
 * - `expires_in_days`: 7, 30, 90 or 365; JSON `null` for a token that never expires; 90 when it is left out. An empty text is not `null`: the
 *   framework turns every empty text into `null` before the request is read (`ConvertEmptyStringsToNull`), so the body as it was sent is
 *   looked at, and `""` is refused instead of making a token that never expires.
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
     * The validated abilities, each once ({@see ApiTokens::create()} keeps them in the order of {@see TokenAbility}).
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_values($this->validated('abilities'));
    }

    /**
     * Days the token works for: the number asked for, null for ever (JSON `null` sent), or the usual 90 when the field is left out.
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
     * Refuses a lifetime that is an empty text, which the framework has turned into `null`: only a `null` the body says in JSON means
     * "for ever". A form cannot say it at all (it has no `null`), so an empty field there is refused as well.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->exists('expires_in_days') && $this->input('expires_in_days') === null && ! $this->sendsJsonNull()) {
                // The same failure, with the same message, as a lifetime that is not one of those offered.
                $validator->addFailure('expires_in_days', 'In');
            }
        });
    }

    /**
     * Whether the body, as it was sent, has `"expires_in_days": null` in JSON.
     */
    private function sendsJsonNull(): bool
    {
        if (! $this->isJson()) {
            return false;
        }

        $body = json_decode($this->getContent(), true);

        return is_array($body) && array_key_exists('expires_in_days', $body) && $body['expires_in_days'] === null;
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
