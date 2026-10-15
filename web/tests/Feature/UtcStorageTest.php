<?php

declare(strict_types=1);

use App\Auth\Accounts;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The `timezone` and `display_timezone` settings config/app.php computes for a given APP_TIMEZONE (null removes it).
 *
 * The configuration file is evaluated again, with the variable swapped for the duration of the call, since the
 * application read its own when it booted.
 *
 * @return array{timezone: mixed, display_timezone: mixed}
 */
function timezoneConfigWith(?string $appTimezone): array
{
    $previous = [$_ENV['APP_TIMEZONE'] ?? null, $_SERVER['APP_TIMEZONE'] ?? null, getenv('APP_TIMEZONE')];

    unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
    putenv('APP_TIMEZONE');

    if ($appTimezone !== null) {
        $_ENV['APP_TIMEZONE'] = $_SERVER['APP_TIMEZONE'] = $appTimezone;
        putenv("APP_TIMEZONE={$appTimezone}");
    }

    try {
        $config = require config_path('app.php');

        return ['timezone' => $config['timezone'], 'display_timezone' => $config['display_timezone']];
    } finally {
        unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
        putenv('APP_TIMEZONE');

        if ($previous[0] !== null) {
            $_ENV['APP_TIMEZONE'] = $previous[0];
        }

        if ($previous[1] !== null) {
            $_SERVER['APP_TIMEZONE'] = $previous[1];
        }

        if ($previous[2] !== false) {
            putenv("APP_TIMEZONE={$previous[2]}");
        }
    }
}

describe('the timezone settings', function (): void {
    it('keeps the application in UTC whatever APP_TIMEZONE says', function (?string $setting): void {
        expect(timezoneConfigWith($setting)['timezone'])->toBe('UTC');
    })->with(['Europe/Paris', 'America/New_York', 'UTC', null]);

    it('gives the display timezone from APP_TIMEZONE, UTC when it is not set', function (?string $setting, string $expected): void {
        expect(timezoneConfigWith($setting)['display_timezone'])->toBe($expected);
    })->with([
        'a zone' => ['Europe/Paris', 'Europe/Paris'],
        'another zone' => ['Asia/Kolkata', 'Asia/Kolkata'],
        'not set' => [null, 'UTC'],
    ]);

    it('runs PHP and Laravel in UTC', function (): void {
        expect(config('app.timezone'))->toBe('UTC')
            ->and(date_default_timezone_get())->toBe('UTC')
            ->and(now()->getTimezone()->getName())->toBe('UTC');
    });

    it('opens every database session in UTC', function (): void {
        // MySQL and MariaDB report the offset they were given, PostgreSQL the name.
        expect(sqlSessionTimezone())->toBeIn(['+00:00', 'UTC']);
    });
});

describe('the dates it stores', function (): void {
    beforeEach(function (): void {
        config(['app.display_timezone' => 'Europe/Paris']);
        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));
    });

    it('are in UTC for an account created the usual way', function (): void {
        User::factory()->create();

        expect(storedDate('users', 'created_at'))->toBe('2026-07-01 10:00:00')
            ->and(storedDate('users', 'updated_at'))->toBe('2026-07-01 10:00:00');
    });

    it('are in UTC for the administrator, who is made the way the installer makes it', function (): void {
        [$administrator] = app(Accounts::class)->administrator('admin@example.test', 'Jane Doe');

        expect($administrator->is_admin)->toBeTrue()
            ->and(storedDate('users', 'created_at'))->toBe('2026-07-01 10:00:00')
            ->and(storedDate('workspaces', 'created_at'))->toBe('2026-07-01 10:00:00')
            ->and(storedDate('workspace_user', 'created_at'))->toBe('2026-07-01 10:00:00');
    });

    it('are sent in UTC by the API', function (): void {
        $user = User::factory()->create();

        $export = $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->assertOk()->json();

        expect($export['user']['created_at'])->toBe('2026-07-01T10:00:00+00:00')
            ->and($export['exported_at'])->toBe('2026-07-01T10:00:00+00:00');
    });
});

describe('the display time', function (): void {
    it('gives the timezone of the instance', function (): void {
        config(['app.display_timezone' => 'Asia/Kolkata']);

        expect(DisplayTime::zone())->toBe('Asia/Kolkata');
    });

    it('expresses a moment in that timezone, without changing the moment or the date it was given', function (): void {
        config(['app.display_timezone' => 'Europe/Paris']);
        $utc = Carbon::parse('2026-01-15 23:30:00', 'UTC');

        $local = DisplayTime::of($utc);

        expect($local->format('Y-m-d H:i'))->toBe('2026-01-16 00:30')
            ->and($local->getTimezone()->getName())->toBe('Europe/Paris')
            ->and($local->equalTo($utc))->toBeTrue()
            ->and($utc->format('Y-m-d H:i'))->toBe('2026-01-15 23:30')
            ->and($utc->getTimezone()->getName())->toBe('UTC');
    });
});
