<?php

declare(strict_types=1);

use App\Inventories\Exports\ExportFormatter;
use App\Support\DisplayTime;

/*
 * The name of the zone a time is written in, which every time of day the server writes for a reader carries: built from the offset
 * the zone has at that moment, so that the same moment has the same name in every language.
 */

describe('the name of the zone of a moment', function (): void {
    it('is UTC for the UTC zone, and UTC with the offset the zone has at that moment for the others', function (string $zone, string $utc, string $name): void {
        expect(DisplayTime::offset((new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone))))->toBe($name);
    })->with([
        'UTC' => ['UTC', '2026-07-01 10:30', 'UTC'],
        'Paris in winter' => ['Europe/Paris', '2026-01-15 10:30', 'UTC+1'],
        'Paris in summer' => ['Europe/Paris', '2026-07-01 10:30', 'UTC+2'],
        'Paris on the night the clocks go forward, before' => ['Europe/Paris', '2026-03-29 00:59', 'UTC+1'],
        'Paris on the night the clocks go forward, after' => ['Europe/Paris', '2026-03-29 01:00', 'UTC+2'],
        'London in winter, which is UTC' => ['Europe/London', '2026-01-15 10:30', 'UTC'],
        'London in summer' => ['Europe/London', '2026-07-01 10:30', 'UTC+1'],
        'New York in winter' => ['America/New_York', '2026-01-15 10:30', 'UTC-5'],
        'New York in summer' => ['America/New_York', '2026-07-01 10:30', 'UTC-4'],
        'India, at half an hour' => ['Asia/Kolkata', '2026-07-01 10:30', 'UTC+5:30'],
        'Nepal, at three quarters of an hour' => ['Asia/Kathmandu', '2026-07-01 10:30', 'UTC+5:45'],
        'Newfoundland in winter, below UTC at half an hour' => ['America/St_Johns', '2026-01-15 10:30', 'UTC-3:30'],
        'Newfoundland in summer' => ['America/St_Johns', '2026-07-01 10:30', 'UTC-2:30'],
        'the furthest east' => ['Pacific/Kiritimati', '2026-07-01 10:30', 'UTC+14'],
        'a zone given as an offset' => ['+02:00', '2026-07-01 10:30', 'UTC+2'],
    ]);
});

describe('a moment written for a reader', function (): void {
    it('states the zone of the formatter after the time, in the language of the reader', function (string $zone, string $locale, string $utc, string $text): void {
        $written = (new ExportFormatter($locale, $zone))->moment(new DateTimeImmutable($utc, new DateTimeZone('UTC')));

        // The spaces ICU puts in a time and the one before the zone are not breaking: one kind is enough to compare.
        expect(str_replace(["\u{202F}", "\u{00A0}"], ' ', $written))->toBe($text)
            // The time and its zone never split over two lines.
            ->and($written)->toContain("\u{00A0}UTC");
    })->with([
        'UTC, English' => ['UTC', 'en', '2026-01-15 10:30', 'Jan 15, 2026, 10:30 AM UTC'],
        'UTC, French' => ['UTC', 'fr', '2026-01-15 10:30', '15 janv. 2026, 10:30 UTC'],
        'Paris in winter, English' => ['Europe/Paris', 'en', '2026-01-15 10:30', 'Jan 15, 2026, 11:30 AM UTC+1'],
        'Paris in winter, French' => ['Europe/Paris', 'fr', '2026-01-15 10:30', '15 janv. 2026, 11:30 UTC+1'],
        'Paris in summer, English' => ['Europe/Paris', 'en', '2026-07-01 10:30', 'Jul 1, 2026, 12:30 PM UTC+2'],
        'Paris in summer, French' => ['Europe/Paris', 'fr', '2026-07-01 10:30', '1 juil. 2026, 12:30 UTC+2'],
        'across midnight, the day and the zone go together' => ['Europe/Paris', 'fr', '2026-07-01 22:30', '2 juil. 2026, 00:30 UTC+2'],
        'New York in summer' => ['America/New_York', 'en', '2026-07-01 10:30', 'Jul 1, 2026, 6:30 AM UTC-4'],
    ]);
});
