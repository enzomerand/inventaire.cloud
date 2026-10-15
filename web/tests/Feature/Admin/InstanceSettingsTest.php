<?php

declare(strict_types=1);

use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A new instance of the service: what the next request would get.
 */
function freshSettings(): InstanceSettings
{
    return new InstanceSettings(app('db'));
}

/**
 * How many statements the callback ran against the settings table.
 */
function settingsQueries(Closure $callback): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $callback();

    $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'settings'))->count();
    DB::disableQueryLog();

    return $count;
}

describe('the registration setting', function (): void {
    it('is open when nothing was ever stored', function (): void {
        expect(DB::table('settings')->count())->toBe(0)
            ->and(freshSettings()->registrationOpen())->toBeTrue();
    });

    it('can be closed, and opened again, for the next request as well', function (): void {
        freshSettings()->setRegistrationOpen(false);

        expect(freshSettings()->registrationOpen())->toBeFalse();

        freshSettings()->setRegistrationOpen(true);

        expect(freshSettings()->registrationOpen())->toBeTrue();
    });

    it('keeps one row per setting, as JSON', function (): void {
        $settings = freshSettings();
        $settings->setRegistrationOpen(false);
        $settings->setRegistrationOpen(false);
        $settings->setRegistrationOpen(true);
        $settings->setRegistrationOpen(false);

        $rows = DB::table('settings')->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->key)->toBe('registration_open')
            ->and(json_decode((string) $rows[0]->value))->toBeFalse()
            ->and($rows[0]->created_at)->not->toBeNull()
            ->and($rows[0]->updated_at)->not->toBeNull();
    });

    it('holds its default when the stored value is not a flag, or not even JSON', function (mixed $stored): void {
        DB::table('settings')->insert(['key' => 'registration_open', 'value' => $stored, 'created_at' => now(), 'updated_at' => now()]);

        expect(freshSettings()->registrationOpen())->toBeTrue();
    })->with([
        'a string' => ['"no"'],
        'a number' => ['0'],
        'null' => ['null'],
        'a list' => ['[false]'],
    ]);

    it('reads every setting at once, and the rows of other settings do not disturb it', function (): void {
        DB::table('settings')->insert(['key' => 'something_else', 'value' => '{"a":1}', 'created_at' => now(), 'updated_at' => now()]);
        freshSettings()->setRegistrationOpen(false);

        expect(freshSettings()->all())->toBe(['registration_open' => false]);
    });
});

describe('the reading of the settings', function (): void {
    it('costs one query a request, however many times a setting is asked', function (): void {
        $settings = freshSettings();

        $queries = settingsQueries(function () use ($settings): void {
            $settings->registrationOpen();
            $settings->registrationOpen();
            $settings->all();
        });

        expect($queries)->toBe(1);
    });

    it('is kept for the request only: another request sees a change at once', function (): void {
        $first = freshSettings();
        expect($first->registrationOpen())->toBeTrue();

        // Another process closes registration while the first request is still served: its copy is its own.
        freshSettings()->setRegistrationOpen(false);

        expect($first->registrationOpen())->toBeTrue()
            ->and(freshSettings()->registrationOpen())->toBeFalse();
    });

    it('forgets what it read when it writes, so the writer sees its own change', function (): void {
        $settings = freshSettings();
        expect($settings->registrationOpen())->toBeTrue();

        $settings->setRegistrationOpen(false);

        expect($settings->registrationOpen())->toBeFalse();
    });

    it('is one service per request: a scoped binding, not a singleton for the life of the process', function (): void {
        $first = app(InstanceSettings::class);

        expect(app(InstanceSettings::class))->toBe($first);

        app()->forgetScopedInstances();

        expect(app(InstanceSettings::class))->not->toBe($first);
    });
});
