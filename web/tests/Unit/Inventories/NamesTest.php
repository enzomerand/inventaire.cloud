<?php

declare(strict_types=1);

use App\Rules\SingleLineText;

/*
 * How names are kept and compared: one Unicode form, the case not counted, the accents counted. Names are the names of
 * inventories, fields and choices.
 */

describe('normalizing a name', function (): void {
    it('trims it and writes an accented letter as one character', function (string $sent, string $kept): void {
        expect(SingleLineText::normalize($sent))->toBe($kept);
    })->with([
        'already composed' => ["Pr\u{00E9}nom", "Pr\u{00E9}nom"],
        'a letter and a combining accent' => ["Pre\u{0301}nom", "Pr\u{00E9}nom"],
        'spaces and invisible characters around it' => ["  \u{200B}Pr\u{00E9}nom \u{00A0}", "Pr\u{00E9}nom"],
        'a Hangul syllable written as jamo' => ["\u{1112}\u{1161}\u{11AB}", "\u{D55C}"],
        'no accent at all' => ['Prenom', 'Prenom'],
        'a capital is kept' => ["\u{00C9}lan", "\u{00C9}lan"],
    ]);

    it('leaves text that is not valid UTF-8 as it is, for the rules to refuse', function (): void {
        expect(SingleLineText::normalize("  bad \xB1 text "))->toBe("  bad \xB1 text ");
    });
});

describe('comparing names', function (): void {
    it('counts them as one when only the case or the way an accent is written differs', function (string $first, string $second): void {
        expect(SingleLineText::fold($first))->toBe(SingleLineText::fold($second));
    })->with([
        'the case' => ['Weight', 'WEIGHT'],
        'German sharp s' => ['Straße', 'STRASSE'],
        'spaces around' => [' Weight ', 'weight'],
        'composed and decomposed' => ["Pr\u{00E9}nom", "Pre\u{0301}nom"],
        'composed capital and decomposed small' => ["\u{00C9}lan", "e\u{0301}lan"],
    ]);

    it('counts them as two when an accent differs', function (string $first, string $second): void {
        expect(SingleLineText::fold($first))->not->toBe(SingleLineText::fold($second));
    })->with([
        'an accent or none' => ["Pr\u{00E9}nom", 'Prenom'],
        'two accents' => ["\u{00E9}", "\u{00E8}"],
        'an accent on another letter' => ["\u{00E9}l\u{00E0}n", "\u{00E0}l\u{00E9}n"],
    ]);
});
