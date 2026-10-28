<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Billing\Billing;
use Illuminate\Validation\Rule;

/**
 * The choice of the owner who goes to Checkout: how often they pay, `month` or `year`. Whether the yearly Price exists on this instance
 * is for the controller to say, once it knows that the instance sells plans.
 */
final class StartCheckoutRequest extends ManageBillingRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'interval' => ['required', 'string', Rule::in(Billing::INTERVALS)],
        ];
    }

    /**
     * The interval that was asked for (`month` or `year`).
     */
    public function chosenInterval(): string
    {
        return $this->string('interval')->toString();
    }
}
