<?php

declare(strict_types=1);

/*
 * Names that hold a control character somewhere inside, and names that are unusual but fine: the same lists for
 * everywhere a person's name is asked (the account page, the creation of an account by an administrator).
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
]);

dataset('unusual names that are fine', [
    'accents and an apostrophe' => ["Zoë O'Brien-Müller"],
    'a name in Han characters' => ['李小龍'],
    'a half-space inside a Persian name' => ["می\u{200C}خواهم"],
    'spaces inside' => ['Ada   Lovelace'],
]);
