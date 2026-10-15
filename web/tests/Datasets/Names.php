<?php

declare(strict_types=1);

/*
 * Names that hold a control character (a text direction control included) somewhere inside, and names that are
 * unusual but fine: the same lists for everywhere a person's name is asked (sign-up, the account page, the creation
 * of an account by an administrator, the installer, `inventaire:make-admin`).
 */

dataset('names with a control character', [
    'a line feed' => ["Ada\nLovelace"],
    'a carriage return' => ["Ada\rLovelace"],
    'both' => ["Ada\r\nLovelace"],
    'a line feed and a forged header' => ["Ada\nBcc: victim@example.com"],
    'a tab' => ["Ada\tLovelace"],
    'a null byte' => ["Ada\0Lovelace"],
    'an escape' => ["Ada\e[31mLovelace"],
    'a next line' => ["Ada\u{85}Lovelace"],
    'a line separator' => ["Ada\u{2028}Lovelace"],
    'a paragraph separator' => ["Ada\u{2029}Lovelace"],
    'a left-to-right embedding' => ["Ada\u{202A}Lovelace"],
    'a right-to-left embedding' => ["Ada\u{202B}Lovelace"],
    'a pop directional formatting' => ["Ada\u{202C}Lovelace"],
    'a left-to-right override' => ["Ada\u{202D}Lovelace"],
    'a right-to-left override' => ["Ada\u{202E}Lovelace"],
    'a left-to-right isolate' => ["Ada\u{2066}Lovelace"],
    'a right-to-left isolate' => ["Ada\u{2067}Lovelace"],
    'a first strong isolate' => ["Ada\u{2068}Lovelace"],
    'a pop directional isolate' => ["Ada\u{2069}Lovelace"],
    'an override that fakes another name' => ["Ada \u{202E}ecnerwoL"],
]);

dataset('unusual names that are fine', [
    'accents and an apostrophe' => ["Zoë O'Brien-Müller"],
    'a name in Han characters' => ['李小龍'],
    'a half-space inside a Persian name' => ["می\u{200C}خواهم"],
    'spaces inside' => ['Ada   Lovelace'],
    'a zero-width joiner inside an emoji' => ["Ada \u{1F469}\u{200D}\u{1F4BB}"],
    'a zero-width joiner inside a Devanagari name' => ["\u{0915}\u{094D}\u{200D}\u{0937}"],
    'a left-to-right mark next to a Hebrew name' => ["Ada \u{200E}\u{05DC}\u{05D5}\u{05D9}"],
]);
