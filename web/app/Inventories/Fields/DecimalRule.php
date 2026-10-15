<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Fails unless the value is a decimal number with at most the given decimals, within the bounds.
 *
 * The decimals are a limit, never a rounding: a value with more of them is refused, so that what is stored is what
 * the person typed (zeros after the last needed decimal are no more decimals: "12.500" is 12.5). The same rule checks
 * the values of the number, amount and percentage fields and the bounds a number field is configured with.
 */
final class DecimalRule implements ValidationRule
{
    /**
     * Sets the limits the number must stay within.
     *
     * @param  int  $decimals  Most decimals the number may have.
     * @param  Decimal|null  $min  Smallest value allowed, if any.
     * @param  Decimal|null  $max  Largest value allowed, if any.
     */
    public function __construct(
        private readonly int $decimals,
        private readonly ?Decimal $min = null,
        private readonly ?Decimal $max = null,
    ) {}

    /**
     * Fails with a message that says what is wrong with the value.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = Decimal::tryFrom($value);

        if ($number === null) {
            // A float that cannot be read back exactly (its exponent is out of any range): rounding it is not an option.
            $fail(is_float($value) && is_finite($value)
                ? __('The :attribute is a number that cannot be read exactly: send it as text, written with a dot.')
                : __('The :attribute must be a number, written with a dot.'));
        } elseif (! $number->fits()) {
            $fail(__('The :attribute is too large.'));
        } elseif ($number->scale() > $this->decimals) {
            $fail($this->decimals === 0
                ? __('The :attribute must be a whole number.')
                : __('The :attribute must have at most :decimals decimals.', ['decimals' => $this->decimals]));
        } elseif ($this->min !== null && $number->compareTo($this->min) < 0) {
            $fail(__('The :attribute must be at least :min.', ['min' => $this->min->format()]));
        } elseif ($this->max !== null && $number->compareTo($this->max) > 0) {
            $fail(__('The :attribute must be at most :max.', ['max' => $this->max->format()]));
        }
    }
}
