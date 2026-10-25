<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\PhotoEntry\Exceptions\PhotoEntryUnavailableException;
use App\PhotoEntry\PhotoEntry;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Whether the photo entry may be used: three conditions, all needed, because the photos leave for a third party (Anthropic) and every
 * analysis is paid for by the instance. The administrator's switch (off until turned on), an API key, and the consent of the workspace.
 */

/**
 * A service of the photo entry that reads the switch as it is stored now, and the key as the configuration holds it.
 */
function photoEntryService(): PhotoEntry
{
    return new PhotoEntry(new InstanceSettings(app('db')));
}

/**
 * A workspace whose owner consented, or did not.
 */
function photoEntryWorkspace(bool $consent): Workspace
{
    $workspace = Workspace::query()->create(['name' => 'Shop']);
    $workspace->forceFill(['photo_entry_enabled' => $consent])->save();

    return $workspace->fresh();
}

describe('the key', function (): void {
    it('is there when it holds something', function (): void {
        config(['services.anthropic.key' => 'sk-ant-test-secret']);

        expect(photoEntryService()->hasKey())->toBeTrue();
    });

    it('is missing when it is not set, empty or blank', function (mixed $key): void {
        config(['services.anthropic.key' => $key]);

        expect(photoEntryService()->hasKey())->toBeFalse();
    })->with([
        'null' => [null],
        'empty' => [''],
        'a space' => [' '],
        'whitespace' => ["  \t\n "],
        'not a string' => [false],
    ]);
});

describe('the instance', function (): void {
    it('offers it only when the administrator turned it on and there is a key', function (?bool $switch, mixed $key, bool $offered): void {
        if ($switch !== null) {
            app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => $switch]);
        }

        config(['services.anthropic.key' => $key]);

        expect(photoEntryService()->availableOnInstance())->toBe($offered);
    })->with([
        'switch on, key' => [true, 'sk-ant-test-secret', true],
        'switch on, no key' => [true, null, false],
        'switch on, empty key' => [true, '', false],
        'switch on, blank key' => [true, '   ', false],
        'switch off, key' => [false, 'sk-ant-test-secret', false],
        'switch off, no key' => [false, null, false],
        'switch never set, key' => [null, 'sk-ant-test-secret', false],
        'switch never set, no key' => [null, null, false],
    ]);

    it('reads the switch as it is stored now', function (): void {
        config(['services.anthropic.key' => 'sk-ant-test-secret']);
        $settings = app(InstanceSettings::class);
        $service = new PhotoEntry($settings);

        expect($service->availableOnInstance())->toBeFalse();

        $settings->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);

        expect($service->availableOnInstance())->toBeTrue();

        $settings->update([InstanceSettings::PHOTO_ENTRY_ENABLED => false]);

        expect($service->availableOnInstance())->toBeFalse();
    });
});

describe('a workspace', function (): void {
    it('may use it only when the instance offers it and its owner consented', function (?bool $switch, mixed $key, bool $consent, bool $enabled): void {
        if ($switch !== null) {
            app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => $switch]);
        }

        config(['services.anthropic.key' => $key]);

        expect(photoEntryService()->enabledFor(photoEntryWorkspace($consent)))->toBe($enabled);
    })->with([
        'everything is on' => [true, 'sk-ant-test-secret', true, true],
        'the owner did not consent' => [true, 'sk-ant-test-secret', false, false],
        'the switch is off' => [false, 'sk-ant-test-secret', true, false],
        'the switch was never set' => [null, 'sk-ant-test-secret', true, false],
        'there is no key' => [true, null, true, false],
        'the key is blank' => [true, '  ', true, false],
        'the switch is off and there is no key' => [false, null, true, false],
        'nothing is on' => [false, null, false, false],
        'only the key' => [null, 'sk-ant-test-secret', false, false],
        'only the switch' => [true, null, false, false],
    ]);

    it('has not consented until its owner says so, a new one included', function (): void {
        $workspace = Workspace::query()->create(['name' => 'Shop']);

        expect($workspace->photo_entry_enabled)->toBeFalse()
            ->and($workspace->fresh()->photo_entry_enabled)->toBeFalse();
    });
});

describe('the consent of a workspace', function (): void {
    beforeEach(function (): void {
        config(['services.anthropic.key' => 'sk-ant-test-secret']);
        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);
    });

    it('is recorded, and withdrawn', function (): void {
        $workspace = photoEntryWorkspace(false);

        photoEntryService()->setConsent($workspace, true);

        expect($workspace->fresh()->photo_entry_enabled)->toBeTrue()
            ->and(photoEntryService()->enabledFor($workspace->fresh()))->toBeTrue();

        photoEntryService()->setConsent($workspace, false);

        expect($workspace->fresh()->photo_entry_enabled)->toBeFalse()
            ->and(photoEntryService()->enabledFor($workspace->fresh()))->toBeFalse();
    });

    it('is recorded for that workspace alone', function (): void {
        $workspace = photoEntryWorkspace(false);
        $other = photoEntryWorkspace(false);

        photoEntryService()->setConsent($workspace, true);

        expect($other->fresh()->photo_entry_enabled)->toBeFalse()
            ->and(DB::table('workspaces')->where('photo_entry_enabled', true)->count())->toBe(1);
    });

    it('cannot be given while the instance does not offer the feature, and stays as it was', function (?bool $switch, mixed $key): void {
        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => $switch ?? false]);
        config(['services.anthropic.key' => $key]);
        $workspace = photoEntryWorkspace(false);

        expect(fn () => photoEntryService()->setConsent($workspace, true))->toThrow(PhotoEntryUnavailableException::class);
        expect($workspace->fresh()->photo_entry_enabled)->toBeFalse();
    })->with([
        'the switch is off' => [false, 'sk-ant-test-secret'],
        'there is no key' => [true, null],
        'a blank key' => [true, '  '],
    ]);

    it('can always be withdrawn, even when the instance stopped offering the feature', function (): void {
        $workspace = photoEntryWorkspace(true);
        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => false]);
        config(['services.anthropic.key' => null]);

        photoEntryService()->setConsent($workspace, false);

        expect($workspace->fresh()->photo_entry_enabled)->toBeFalse();
    });

    it('is kept when the instance stops offering the feature, and works again when it resumes', function (): void {
        $workspace = photoEntryWorkspace(true);

        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => false]);

        expect(photoEntryService()->enabledFor($workspace->fresh()))->toBeFalse()
            ->and($workspace->fresh()->photo_entry_enabled)->toBeTrue();

        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);

        expect(photoEntryService()->enabledFor($workspace->fresh()))->toBeTrue();
    });
});

it('is a service the container builds', function (): void {
    expect(app(PhotoEntry::class))->toBeInstanceOf(PhotoEntry::class);
});
