<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * What a text of several lines must be: a description, a note, the long text field of an item.
 *
 * Line breaks and tabs are fine (that is what the text is for); the line breaks of every system are written as "\n"
 * by {@see self::normalize()} so the same text is the same everywhere. Every other control character is refused, and so
 * are the separators of lines and paragraphs of Unicode (U+2028, U+2029: more line breaks, which no system agrees on)
 * and the text direction controls, for the reasons given at {@see SingleLineText}. Text that is not valid UTF-8 is
 * refused too.
 *
 * The length is the business of the rules next to this one.
 */
final class MultiLineText implements ValidationRule
{
    /**
     * The text with "\n" for every line break and without the spaces, invisible characters and control characters at
     * both ends. Run it before validating, so that the length counted is the length stored.
     */
    public static function normalize(string $text): string
    {
        return SingleLineText::trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Fails when the text holds a control character other than a line break or a tab, a separator of lines or
     * paragraphs, a text direction control, or is not valid UTF-8.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // 0 when there is none; false when the text is not valid UTF-8.
        if (preg_match('/[^\P{Cc}\n\t]|[\p{Zl}\p{Zp}]/u', $value) !== 0) {
            $fail(__('The :attribute must not contain control characters other than line breaks.'));

            return;
        }

        if (preg_match(SingleLineText::DIRECTION_CONTROLS, $value) === 1) {
            $fail(__('The :attribute must not contain characters that change the direction of the text.'));
        }
    }
}
