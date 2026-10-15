<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * What a person's name must be, wherever it is asked: when an administrator creates an account and when the person
 * changes it on their account page.
 *
 * A name is one line of text, at most {@see self::MAX_LENGTH} characters, with something in it. It is shown in the
 * interface and written in mails, so it holds no control character: no line break (a way to add lines to a mail or
 * to fake another row in a list), no tab, no escape. Spaces, accents, apostrophes and every script are fine. Text that
 * is not valid UTF-8 is refused too, since the database would refuse to store it.
 *
 * The request trims the name before it is validated ({@see self::trim()}), so a name made of spaces counts as missing.
 */
final class PersonName implements ValidationRule
{
    /** Longest name, in characters. */
    public const MAX_LENGTH = 255;

    /**
     * The rules a name must pass, in the order they run.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:'.self::MAX_LENGTH, new self];
    }

    /**
     * The name without the spaces, invisible characters and control characters at both ends.
     */
    public static function trim(string $name): string
    {
        return preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $name) ?? $name;
    }

    /**
     * Fails when the name holds a control character (line breaks and separators of lines or paragraphs included), or
     * is not valid UTF-8.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // 0 when there is none; false when the text is not valid UTF-8.
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) !== 0) {
            $fail(__('The :attribute must not contain line breaks or other control characters.'));
        }
    }
}
