<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * What a person's name must be, wherever it is asked: at sign-up, when an administrator creates an account, when the
 * person changes it on their account page, and for the administrator the installer or `inventaire:make-admin` creates.
 *
 * A name is one line of text, at most {@see self::MAX_LENGTH} characters, with something in it. It is shown in the
 * interface and written in mails, so it holds no control character: no line break (a way to add lines to a mail or
 * to fake another row in a list), no tab, no escape. Nor does it hold a text direction control (U+202A to U+202E and
 * U+2066 to U+2069, the embeddings, overrides and isolates), which reorders what is displayed around it: a name could
 * then make the text next to it, in a list or in a mail, read differently from what it says. The other formatting
 * characters stay allowed, since names need them: the zero-width joiner of emoji and of some scripts, the zero-width
 * non-joiner of Persian, and the left-to-right and right-to-left marks. Spaces, accents, apostrophes and every script
 * are fine. Text that is not valid UTF-8 is refused too, since the database would refuse to store it.
 *
 * The request trims the name before it is validated ({@see self::trim()}), so a name made of spaces counts as missing.
 */
final class PersonName implements ValidationRule
{
    /** Longest name, in characters. */
    public const MAX_LENGTH = 255;

    /** The text direction controls a name must not hold: U+202A to U+202E and U+2066 to U+2069. */
    private const DIRECTION_CONTROLS = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

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
     * Fails when the name holds a control character (line breaks and separators of lines or paragraphs included), a
     * text direction control, or is not valid UTF-8.
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

            return;
        }

        if (preg_match(self::DIRECTION_CONTROLS, $value) === 1) {
            $fail(__('The :attribute must not contain characters that change the direction of the text.'));
        }
    }
}
