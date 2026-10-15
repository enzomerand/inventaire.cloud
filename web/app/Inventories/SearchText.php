<?php

declare(strict_types=1);

namespace App\Inventories;

use Normalizer;

/**
 * The form a text takes to be searched, and the pattern that looks for it.
 *
 * Searching ignores the case and the accents ("Électricité" is found by `electricite`, `ÉLEC` or `électr`), and the
 * letters that are written with two ("œuvre" by `oeuvre`, "Straße" by `strasse`) and the ones with a stroke ("Łódź" by
 * `lodz`). Neither the database collations nor its `LIKE` agree on that from one engine to another, so both sides are
 * brought to the same plain form here, in PHP: the text of an item when it is written ({@see ItemSearchText}), and the
 * words a person types when they search.
 *
 * The form is: the compatibility decomposition of Unicode (NFKD: an accented letter becomes the letter and its accents,
 * a ligature or a full-width letter becomes plain letters), the combining marks dropped, the case lowered, `œ`, `æ` and
 * `ß` written out, `ø`, `ł` and `đ` made `o`, `l` and `d`, every run of white space made one space, the ends trimmed.
 */
final class SearchText
{
    /**
     * The letters that decomposing does not split (the ligatures, and the letters with a stroke), written the way a person
     * types them on a plain keyboard. The text is lowered before, so the capitals need no entry.
     */
    private const WRITTEN_OUT = ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', 'ø' => 'o', 'ł' => 'l', 'đ' => 'd'];

    /** The character that escapes the wildcards of a `LIKE` pattern (see {@see self::pattern()}); the `ESCAPE` clause of {@see ItemSearchText::restrict()} names it. */
    public const ESCAPE = '!';

    /**
     * The text in the form it is searched in. What is not valid UTF-8 is replaced by `?` first, so that any input gives
     * an answer.
     */
    public static function normalize(string $text): string
    {
        $text = mb_scrub($text);
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);
        $plain = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $text : $decomposed) ?? $text;
        $written = strtr(mb_strtolower($plain), self::WRITTEN_OUT);

        return trim(preg_replace('/\s+/u', ' ', $written) ?? $written);
    }

    /**
     * The words of a text in the form they are searched in, each one once, in the order they come. Empty when the text
     * holds nothing to search for.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * The `LIKE` pattern that finds a word anywhere in a text, to use with `ESCAPE '!'`: `%`, `_` and the escape character
     * itself are escaped, so that they stand for themselves and never for "anything".
     */
    public static function pattern(string $word): string
    {
        return '%'.strtr($word, [self::ESCAPE => self::ESCAPE.self::ESCAPE, '%' => self::ESCAPE.'%', '_' => self::ESCAPE.'_']).'%';
    }
}
