<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Auth\EmailAddress;
use App\Rules\PersonName;

/**
 * The declaration of a withdrawal from the contract of a workspace: the name of the person who makes it and the address the
 * acknowledgement is sent to (both prefilled by the page from the account, and editable).
 *
 * Only the owners may (see {@see ManageBillingRequest}); the contract the declaration is about is the subscription the workspace has,
 * which the person does not choose: the page shows it read-only and the server reads it.
 */
final class WithdrawFromContractRequest extends ManageBillingRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => PersonName::rules(),
            'email' => EmailAddress::rules(),
        ];
    }

    /**
     * The name, trimmed.
     */
    public function declaredName(): string
    {
        return $this->string('name')->toString();
    }

    /**
     * The address in its canonical form ({@see EmailAddress::normalize()}), normalized once, before the validation.
     */
    public function declaredEmail(): string
    {
        return $this->string('email')->toString();
    }

    /**
     * Trims the name and normalizes the address before they are validated, so that what is checked is what is used.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailAddress::normalize($this->input('email'))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => PersonName::trim($this->input('name'))]);
        }
    }
}
