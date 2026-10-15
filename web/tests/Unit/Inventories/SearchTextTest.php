<?php

declare(strict_types=1);

use App\Inventories\SearchText;

describe('the form a text is searched in', function (): void {
    it('ignores the case and the accents', function (string $text, string $searched): void {
        expect(SearchText::normalize($text))->toBe($searched);
    })->with([
        'accents and capitals' => ['Électricité', 'electricite'],
        'a capital accent' => ['ÉLEC', 'elec'],
        'every accent of French' => ['àâäéèêëîïôöùûüÿç ÀÂÄÉÈÊËÎÏÔÖÙÛÜŸÇ', 'aaaeeeeiioouuuyc aaaeeeeiioouuuyc'],
        'a letter and a combining accent' => ["e\u{0301}te\u{0301}", 'ete'],
        'the letters with a stroke are written without it' => ['Ñandú Škoda Łódź Ærø', 'nandu skoda lodz aero'],
        'the capital letters with a stroke too' => ['Øre Łukasz Đorđe ØŁĐ', 'ore lukasz dorde old'],
        'the letters written with two' => ['Œuvre Æther Straße STRAẞE', 'oeuvre aether strasse strasse'],
        'ligatures and full-width letters' => ['ﬁnal ＡＢＣ ①', 'final abc 1'],
        'a capital I with a dot' => ['İstanbul', 'istanbul'],
    ]);

    it('makes every run of white space one space and trims the ends', function (): void {
        expect(SearchText::normalize("  a \t b\n\nc\u{00A0}\u{00A0}d\u{2003}e  "))->toBe('a b c d e')
            ->and(SearchText::normalize(" \n\t "))->toBe('')
            ->and(SearchText::normalize(''))->toBe('');
    });

    it('is the same whatever the form the text arrives in', function (): void {
        expect(SearchText::normalize("Caf\u{00E9}"))->toBe(SearchText::normalize("Cafe\u{0301}"))
            ->and(SearchText::normalize(SearchText::normalize('Électricité À Côté')))->toBe(SearchText::normalize('Électricité À Côté'));
    });

    it('does not choke on what is not valid UTF-8', function (): void {
        expect(SearchText::normalize("caf\xE9 noir"))->toBe('caf? noir');
    });

    it('leaves the characters of a pattern alone', function (): void {
        expect(SearchText::normalize('100% a_b !'))->toBe('100% a_b !');
    });
});

describe('the words of a search', function (): void {
    it('are the normalized words, each once, in the order they come', function (): void {
        expect(SearchText::words('  Électricité  PLOMBERIE électricité '))->toBe(['electricite', 'plomberie'])
            ->and(SearchText::words("a\tb\nc"))->toBe(['a', 'b', 'c']);
    });

    it('are none when nothing is left to look for', function (string $typed): void {
        expect(SearchText::words($typed))->toBe([]);
    })->with(['nothing' => '', 'spaces' => '   ', 'line breaks and tabs' => " \n\t\r\n "]);
});

describe('the pattern that finds a word', function (): void {
    it('looks for the word anywhere', function (): void {
        expect(SearchText::pattern('elec'))->toBe('%elec%');
    });

    it('escapes the wildcards and the escape character so that they stand for themselves', function (string $word, string $pattern): void {
        expect(SearchText::pattern($word))->toBe($pattern);
    })->with([
        'a percent sign' => ['100%', '%100!%%'],
        'an underscore' => ['a_b', '%a!_b%'],
        'the escape character' => ['hello!', '%hello!!%'],
        'all three' => ['!%_', '%!!!%!_%'],
        'a backslash is not special' => ['a\\b', '%a\\b%'],
    ]);
});
