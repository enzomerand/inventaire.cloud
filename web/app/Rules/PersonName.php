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
 * A name is one line of text, at most {@see self::MAX_LENGTH} characters, with something in it. The characters are
 * judged by {@see SingleLineText}, which every one-line text of the application shares. The request trims the name
 * before it is validated ({@see self::trim()}), so a name made of spaces counts as missing.
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
        return SingleLineText::trim($name);
    }

    /**
     * Fails when the name holds a control character (line breaks and separators of lines or paragraphs included), a
     * text direction control, or is not valid UTF-8.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        (new SingleLineText)->validate($attribute, $value, $fail);
    }
}
