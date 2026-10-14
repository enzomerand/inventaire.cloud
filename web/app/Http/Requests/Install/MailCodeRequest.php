<?php

declare(strict_types=1);

namespace App\Http\Requests\Install;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The verification code typed back by the administrator.
 */
final class MailCodeRequest extends FormRequest
{
    /**
     * The route is already protected by the setup token middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['code' => ['required', 'digits:6']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => __('code')];
    }
}
