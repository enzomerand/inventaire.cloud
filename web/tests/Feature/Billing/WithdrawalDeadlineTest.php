<?php

declare(strict_types=1);

use App\Billing\WithdrawalDeadline;
use Carbon\CarbonImmutable;

/*
 * The end of the right of withdrawal, counted the way article L221-19 of the consumer code counts it: the day of the conclusion is not
 * counted, the period ends at the last hour of the fourteenth day, and a period that ends on a Saturday, a Sunday or a public holiday runs
 * on to the next working day. Days are the days of the instance, not of UTC.
 */

beforeEach(function (): void {
    config(['app.display_timezone' => 'Europe/Paris']);
});

/**
 * The end of the right for a contract concluded at a moment (in UTC), as an ISO 8601 moment in UTC.
 */
function deadlineFor(string $concluded): string
{
    return WithdrawalDeadline::end(CarbonImmutable::parse($concluded, 'UTC'))->toIso8601String();
}

describe('the fourteen days', function (): void {
    it('end at the last second of the fourteenth day after the one the contract was concluded, whatever the hour it was', function (string $concluded, string $ends): void {
        expect(deadlineFor($concluded))->toBe($ends);
    })->with([
        // Concluded on Tuesday 3 November 2026 in Paris, at any hour: Tuesday 17 November is the fourteenth day, to 23:59:59 (22:59:59 UTC).
        'at 15:00' => ['2026-11-03 14:00:00', '2026-11-17T22:59:59+00:00'],
        'just after midnight' => ['2026-11-02 23:00:01', '2026-11-17T22:59:59+00:00'],
        'at the end of the day' => ['2026-11-03 22:59:59', '2026-11-17T22:59:59+00:00'],
        // A moment of UTC that is the next day in Paris.
        'at 23:30 UTC, which is the next day in Paris' => ['2026-11-03 23:30:00', '2026-11-18T22:59:59+00:00'],
    ]);

    it('are counted in calendar days when the clocks change: a day of 25 hours is one day', function (string $concluded, string $ends): void {
        expect(deadlineFor($concluded))->toBe($ends);
    })->with([
        // Concluded on Tuesday 20 October 2026 (UTC+2), the clocks go back on Sunday 25 October: Tuesday 3 November is in UTC+1.
        'the clocks go back' => ['2026-10-20 10:00:00', '2026-11-03T22:59:59+00:00'],
        // Concluded on Tuesday 17 March 2027 (UTC+1), the clocks go forward on Sunday 28 March: Tuesday 31 March is in UTC+2.
        'the clocks go forward' => ['2027-03-17 10:00:00', '2027-03-31T21:59:59+00:00'],
        // Concluded the day before the change, at a moment that is the next day in UTC of the other offset.
        'concluded just before the clocks go forward' => ['2027-03-27 23:30:00', '2027-04-12T21:59:59+00:00'],
    ]);

    it('run on to the next working day when the fourteenth day is a Saturday, a Sunday or a holiday', function (string $concluded, string $ends): void {
        expect(deadlineFor($concluded))->toBe($ends);
    })->with([
        // Concluded on Saturday 7 November: Saturday 21 November, then Sunday, then Monday 23 November.
        'a Saturday' => ['2026-11-07 14:00:00', '2026-11-23T22:59:59+00:00'],
        // Concluded on Sunday 8 November: Sunday 22 November, then Monday 23 November.
        'a Sunday' => ['2026-11-08 14:00:00', '2026-11-23T22:59:59+00:00'],
        // Concluded on Wednesday 28 October: Wednesday 11 November is a holiday, so Thursday 12 November.
        'a holiday in the week' => ['2026-10-28 14:00:00', '2026-11-12T22:59:59+00:00'],
        // Concluded on Friday 24 April 2026: Friday 8 May is a holiday, then the week-end, then Monday 11 May.
        'a holiday on a Friday, then the week-end' => ['2026-04-24 10:00:00', '2026-05-11T21:59:59+00:00'],
        // Concluded on Monday 23 March 2026: Monday 6 April is Easter Monday, so Tuesday 7 April.
        'Easter Monday' => ['2026-03-23 10:00:00', '2026-04-07T21:59:59+00:00'],
        // Concluded on Monday 11 December 2028: Monday 25 December is a holiday, so Tuesday 26 December.
        'Christmas' => ['2028-12-11 10:00:00', '2028-12-26T22:59:59+00:00'],
        // Concluded on Wednesday 17 December 2025: Wednesday 31 December, a working day, is the last.
        'the end of a year, a working day' => ['2025-12-17 10:00:00', '2025-12-31T22:59:59+00:00'],
        // Concluded on Wednesday 18 December 2025: Wednesday 1 January 2026 is a holiday, so Thursday 2 January.
        'the first of January' => ['2025-12-18 10:00:00', '2026-01-02T22:59:59+00:00'],
    ]);
});

describe('the French public holidays', function (): void {
    it('are the eleven national ones', function (): void {
        expect(array_keys(WithdrawalDeadline::holidays(2026)))->toBe([
            '2026-01-01', '2026-04-06', '2026-05-01', '2026-05-08', '2026-05-14', '2026-05-25',
            '2026-07-14', '2026-08-15', '2026-11-01', '2026-11-11', '2026-12-25',
        ]);
    });

    it('move with Easter: Easter Monday, Ascension and Whit Monday', function (int $year, string $easter, string $monday, string $ascension, string $whitMonday): void {
        $holidays = WithdrawalDeadline::holidays($year);

        expect(WithdrawalDeadline::easter($year)->toDateString())->toBe($easter)
            ->and($holidays)->toHaveKeys([$monday, $ascension, $whitMonday]);
    })->with([
        [2024, '2024-03-31', '2024-04-01', '2024-05-09', '2024-05-20'],
        [2025, '2025-04-20', '2025-04-21', '2025-05-29', '2025-06-09'],
        [2026, '2026-04-05', '2026-04-06', '2026-05-14', '2026-05-25'],
        [2027, '2027-03-28', '2027-03-29', '2027-05-06', '2027-05-17'],
        [2028, '2028-04-16', '2028-04-17', '2028-05-25', '2028-06-05'],
    ]);

    it('has Easter right across the centuries (the Gregorian algorithm)', function (int $year, string $easter): void {
        expect(WithdrawalDeadline::easter($year)->toDateString())->toBe($easter);
    })->with([
        [1961, '1961-04-02'],
        [1999, '1999-04-04'],
        [2000, '2000-04-23'],
        [2038, '2038-04-25'],
        [2100, '2100-03-28'],
    ]);

    it('tells a working day from the others', function (string $day, bool $nonWorking): void {
        expect(WithdrawalDeadline::isNonWorking(CarbonImmutable::parse($day)))->toBe($nonWorking);
    })->with([
        ['2026-11-09', false],
        ['2026-11-11', true],
        ['2026-11-14', true],
        ['2026-11-15', true],
        ['2026-07-14', true],
        ['2026-07-15', false],
    ]);
});
