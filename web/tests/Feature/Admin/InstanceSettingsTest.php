<?php

declare(strict_types=1);

use App\Settings\InstanceSettings;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    it('is closed, with one warning, when the stored value is not a flag', function (string $stored): void {
        Log::spy();
        DB::table('settings')->insert(['key' => 'registration_open', 'value' => $stored, 'created_at' => now(), 'updated_at' => now()]);
        $settings = freshSettings();

        // Asked many times, in many ways, during one request: it is said once.
        expect($settings->registrationOpen())->toBeFalse()
            ->and($settings->registrationOpen())->toBeFalse()
            ->and($settings->all())->toBe(['registration_open' => false]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['setting' => 'registration_open'])->once();
    })->with([
        'a string' => ['"no"'],
        'the word false as a string' => ['"false"'],
        'a number' => ['0'],
        'one' => ['1'],
        'null' => ['null'],
        'a list' => ['[false]'],
        'an object' => ['{"value":true}'],
    ]);

    it('is closed, with one warning, when the stored value is not even JSON', function (): void {
        Log::spy();
        $database = Mockery::mock(DatabaseManager::class);
        // The json column of every engine refuses such a value, so the rows are what a broken driver or a hand-edited
        // file could hand over.
        $database->shouldReceive('table->pluck')->once()->andReturn(collect(['registration_open' => '{not json', 'something_else' => '{"a":1}']));
        $settings = new InstanceSettings($database);

        expect($settings->registrationOpen())->toBeFalse()
            ->and($settings->registrationOpen())->toBeFalse();

        Log::shouldHaveReceived('warning')->once();
    });

    it('is closed when the database hands over something that is not text', function (): void {
        Log::spy();
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table->pluck')->once()->andReturn(collect(['registration_open' => 0]));

        expect((new InstanceSettings($database))->registrationOpen())->toBeFalse();

        Log::shouldHaveReceived('warning')->once();
    });

    it('does not write what is stored in the log', function (): void {
        Log::spy();
        DB::table('settings')->insert(['key' => 'registration_open', 'value' => '"secret-value"', 'created_at' => now(), 'updated_at' => now()]);

        freshSettings()->registrationOpen();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains($message.json_encode($context), 'secret'))->once();
    });

    it('says nothing when the setting is missing or well formed, or when it is another one that is not', function (): void {
        Log::spy();
        DB::table('settings')->insert(['key' => 'something_else', 'value' => '"whatever"', 'created_at' => now(), 'updated_at' => now()]);

        expect(freshSettings()->registrationOpen())->toBeTrue();

        freshSettings()->setRegistrationOpen(false);

        expect(freshSettings()->registrationOpen())->toBeFalse();

        freshSettings()->setRegistrationOpen(true);

        expect(freshSettings()->registrationOpen())->toBeTrue();

        Log::shouldNotHaveReceived('warning');
    });

    it('changes only the settings it is given', function (): void {
        $settings = freshSettings();
        $settings->setRegistrationOpen(false);

        $settings->update([]);

        expect(freshSettings()->registrationOpen())->toBeFalse();

        $settings->update(['registration_open' => true]);

        expect(freshSettings()->registrationOpen())->toBeTrue()
            ->and(DB::table('settings')->count())->toBe(1);
    });

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
