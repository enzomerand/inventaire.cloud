<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What an administrator can change about an account: whether it administers the instance.
 */
final class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'is_admin' => ['required', 'boolean'],
        ];
    }

    /**
     * Whether the account is to be an administrator.
     */
    public function administrator(): bool
    {
        return $this->boolean('is_admin');
    }
}
