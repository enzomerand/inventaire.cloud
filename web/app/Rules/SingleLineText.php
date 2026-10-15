<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * What a piece of text must be to sit on one line wherever it is shown: a person's name, the name of an inventory or
 * of a field, a short text value, a tag.
 *
 * It holds no control character: no line break (a way to add lines to a mail or to fake another row in a list), no
 * tab, no escape. Nor does it hold a text direction control (U+202A to U+202E and U+2066 to U+2069, the embeddings,
 * overrides and isolates), which reorders what is displayed around it: a text could then make the text next to it, in
 * a list or in a mail, read differently from what it says. The other formatting characters stay allowed, since
 * writing needs them: the zero-width joiner of emoji and of some scripts, the zero-width non-joiner of Persian, and
 * the left-to-right and right-to-left marks. Spaces, accents, apostrophes and every script are fine. Text that is not
 * valid UTF-8 is refused too, since the database would refuse to store it.
 *
 * The length, and whether something is required, are the business of the rules next to this one: this only judges
 * the characters.
 */
final class SingleLineText implements ValidationRule
{
    /** The text direction controls a text must not hold: U+202A to U+202E and U+2066 to U+2069. */
    public const DIRECTION_CONTROLS = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * The text without the spaces, invisible characters and control characters at both ends.
     */
    public static function trim(string $text): string
    {
        return preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $text) ?? $text;
    }

    /**
     * Fails when the text holds a control character (line breaks and separators of lines or paragraphs included), a
     * text direction control, or is not valid UTF-8.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = is_string($value) ? self::problem($value) : null;

        if ($problem !== null) {
            $fail($problem);
        }
    }

    /**
     * What is wrong with the text as a line, in words (`:attribute` stands for the name of what holds it), or null when
     * it is fine. For the code that judges many texts at once and words its own message around them.
     */
    public static function problem(string $text): ?string
    {
        // 0 when there is none; false when the text is not valid UTF-8.
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $text) !== 0) {
            return __('The :attribute must not contain line breaks or other control characters.');
        }

        if (preg_match(self::DIRECTION_CONTROLS, $text) === 1) {
            return __('The :attribute must not contain characters that change the direction of the text.');
        }

        return null;
    }
}
