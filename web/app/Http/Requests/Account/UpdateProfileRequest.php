<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\PersonName;
use App\Services\LocaleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a person can change about their own account: the name and the language. Both are optional (what is not
 * sent is left as it is) but not empty when sent.
 */
final class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', ...PersonName::rules()],
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
     * Trims the name before it is validated, so that a name made of spaces counts as empty. The rules of a name are
     * those of {@see PersonName}, the same as when an administrator creates the account.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => PersonName::trim($name)]);
        }
    }
}
