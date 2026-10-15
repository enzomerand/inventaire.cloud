<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Services\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a person can change about their own account: the name and the language. Both are optional (what is not
 * sent is left as it is) but not empty when sent.
 */
final class UpdateProfileRequest extends FormRequest
{
    /** Longest name, in characters (the same limit as when the account is created). */
    public const NAME_MAX_LENGTH = 255;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'locale' => ['sometimes', 'required', 'string', Rule::in(app(LocaleResolver::class)->supported())],
        ];
    }

    /**
     * The new name, trimmed, or null when it is not being changed.
     */
    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->toString() : null;
    }

    /**
     * The new language, or null when it is not being changed.
     */
    public function locale(): ?string
    {
        return $this->has('locale') ? $this->string('locale')->toString() : null;
    }

    /**
     * Trims the name before it is validated, so that a name made of spaces counts as empty.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $name) ?? $name]);
        }
    }
}
