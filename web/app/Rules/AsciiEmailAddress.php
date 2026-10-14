<?php

declare(strict_types=1);

namespace App\Rules;

use App\Auth\EmailAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses an address that is not made of plain ASCII once normalized
 * ({@see EmailAddress::normalize()}).
 *
 * The domain of a valid address has been converted to ASCII by then, so what
 * is left to refuse is an accented or non-Latin local part (the text before
 * the @), which cannot be told apart from a plain one by every database, and
 * a domain that cannot be converted at all. The `email` rule is left to judge
 * the shape of the address.
 */
final class AsciiEmailAddress implements ValidationRule
{
    /**
     * Fails when the address, as given, holds a non-ASCII character.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $at = strrpos($value, '@');

        if ($at === false) {
            return;
        }

        if (EmailAddress::hasNonAscii(substr($value, 0, $at))) {
            $fail(__('The :attribute must not contain accents or other special letters before the @ sign.'));

            return;
        }

        if (EmailAddress::hasNonAscii(substr($value, $at + 1))) {
            $fail('validation.email')->translate();
        }
    }
}
