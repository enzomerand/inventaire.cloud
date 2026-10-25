<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Settings\InstanceSettings;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/tokens.php';

uses(RefreshDatabase::class);

/*
 * The consent of a workspace to the photo entry through the API: `PUT /workspaces/{workspace}/photo-entry`. Its owner chooses, at their
 * browser; the instance must offer the feature (the switch of the administrator and an API key) for it to be turned on, and turning it off
 * always works. What the feature does once it is on is for the tests of the analyses.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
    config(['services.anthropic.key' => null]);
});

/**
 * Makes the instance offer the photo entry: the administrator turned it on and there is an API key.
 */
function photoEntryOffered(): void
{
    app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);
    config(['services.anthropic.key' => 'sk-ant-test-secret']);
}

/**
 * The address of the consent of a workspace.
 */
function photoEntryUri(Workspace $workspace): string
{
    return "/api/v1/workspaces/{$workspace->ulid}/photo-entry";
}

/**
 * What the workspace holds now, read from the database.
 */
function photoEntryStored(Workspace $workspace): bool
{
    return (bool) DB::table('workspaces')->where('id', $workspace->id)->value('photo_entry_enabled');
}

describe('a workspace', function (): void {
    it('starts without the consent of its owner', function (): void {
        $team = inventoryTeam();

        expect(photoEntryStored($team['workspace']))->toBeFalse()
            ->and($team['workspace']->fresh()->photo_entry_enabled)->toBeFalse();
    });
});

describe('the owner', function (): void {
    it('turns the photo entry on and off, and is answered with what it now is', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $uri = photoEntryUri($team['workspace']);

        inventoryCall($this, $team['owner'], 'PUT', $uri, ['enabled' => true])
            ->assertOk()
            ->assertExactJson(['data' => ['enabled' => true]]);

        expect(photoEntryStored($team['workspace']))->toBeTrue();

        inventoryCall($this, $team['owner'], 'PUT', $uri, ['enabled' => false])
            ->assertOk()
            ->assertExactJson(['data' => ['enabled' => false]]);

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    });

    it('can say what it already is', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $uri = photoEntryUri($team['workspace']);

        inventoryCall($this, $team['owner'], 'PUT', $uri, ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        inventoryCall($this, $team['owner'], 'PUT', $uri, ['enabled' => true])->assertOk();
        inventoryCall($this, $team['owner'], 'PUT', $uri, ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);

        expect(photoEntryStored($team['workspace']))->toBeTrue();
    });

    it('changes the workspace of the path and no other', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Warehouse');

        inventoryCall($this, $team['owner'], 'PUT', photoEntryUri($team['workspace']), ['enabled' => true])->assertOk();

        expect(photoEntryStored($team['workspace']))->toBeTrue()
            ->and(photoEntryStored($other))->toBeFalse()
            ->and(DB::table('workspaces')->where('photo_entry_enabled', true)->count())->toBe(1);
    });

    it('accepts the usual spellings of a boolean', function (mixed $value, bool $expected): void {
        photoEntryOffered();
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PUT', photoEntryUri($team['workspace']), ['enabled' => $value])
            ->assertOk()
            ->assertJsonPath('data.enabled', $expected);
    })->with([
        'true' => [true, true],
        'false' => [false, false],
        'one' => [1, true],
        'zero' => [0, false],
        'the string one' => ['1', true],
        'the string zero' => ['0', false],
    ]);

    it('is refused a value that is not a boolean, and nothing changes', function (mixed $body): void {
        photoEntryOffered();
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PUT', photoEntryUri($team['workspace']), $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    })->with([
        'nothing' => [[]],
        'null' => [['enabled' => null]],
        'a word' => [['enabled' => 'maybe']],
        'a list' => [['enabled' => [true]]],
        'another name' => [['photo_entry_enabled' => true]],
    ]);
});

describe('when the instance does not offer the photo entry', function (): void {
    it('refuses to turn it on, with a reason, and nothing changes', function (bool $switch, ?string $key): void {
        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => $switch]);
        config(['services.anthropic.key' => $key]);
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PUT', photoEntryUri($team['workspace']), ['enabled' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'photo_entry_unavailable')
            ->assertJsonPath('message', 'The photo entry is not available on this instance: ask its administrator.');

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    })->with([
        'the administrator has it off' => [false, 'sk-ant-test-secret'],
        'there is no key' => [true, null],
        'the key is blank' => [true, '   '],
        'neither' => [false, null],
    ]);

    it('answers in French as well', function (): void {
        $team = inventoryTeam();

        $response = inventoryCall($this, $team['owner'], 'PUT', photoEntryUri($team['workspace']), ['enabled' => true], ['Accept-Language' => 'fr'])
            ->assertStatus(409);

        expect($response->json('message'))->toContain('pas disponible');
    });

    it('lets the owner turn it off whatever the instance offers, so a workspace can always withdraw its consent', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $uri = photoEntryUri($team['workspace']);
        inventoryCall($this, $team['owner'], 'PUT', $uri, ['enabled' => true])->assertOk();

        app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => false]);
        config(['services.anthropic.key' => null]);

        inventoryCall($this, $team['owner'], 'PUT', $uri, ['enabled' => false])
            ->assertOk()
            ->assertExactJson(['data' => ['enabled' => false]]);

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    });

    it('checks the body before it checks what the instance offers', function (): void {
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PUT', photoEntryUri($team['workspace']), ['enabled' => 'maybe'])->assertUnprocessable();
    });
});

describe('who may call it', function (): void {
    it('is for the owner only: an editor and a viewer are refused, a stranger is told the workspace is not there', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $uri = photoEntryUri($team['workspace']);

        inventoryCall($this, $team['editor'], 'PUT', $uri, ['enabled' => true])->assertForbidden();
        inventoryCall($this, $team['viewer'], 'PUT', $uri, ['enabled' => true])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'PUT', $uri, ['enabled' => true])->assertNotFound();

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    });

    it('tells a person who may not nothing about the body', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $uri = photoEntryUri($team['workspace']);

        inventoryCall($this, $team['editor'], 'PUT', $uri, ['enabled' => 'maybe'])->assertForbidden();
        inventoryCall($this, $team['stranger'], 'PUT', $uri, ['enabled' => 'maybe'])->assertNotFound();
        inventoryCall($this, $team['editor'], 'PUT', $uri)->assertForbidden();
    });

    it('does not let an owner of another workspace change this one', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $elsewhere = inventoryTeam();

        inventoryCall($this, $elsewhere['owner'], 'PUT', photoEntryUri($team['workspace']), ['enabled' => true])->assertNotFound();

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    });

    it('answers a workspace that does not exist with a 404', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PUT', '/api/v1/workspaces/01arz3ndektsv4rrffq69g5fav/photo-entry', ['enabled' => true])->assertNotFound();
    });

    it('refuses a visitor', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();

        $this->putJson(photoEntryUri($team['workspace']), ['enabled' => true], frontend())->assertUnauthorized();

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    });

    it('is for the web front only: the owner calling from elsewhere is refused with a reason', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();

        $this->flushSession();
        $this->actingAs($team['owner'])->json('PUT', photoEntryUri($team['workspace']), ['enabled' => true])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_stateful');

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    });

    it('is for a person at their browser: a token is refused, whatever it can do', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        $uri = photoEntryUri($team['workspace']);

        foreach ([['*'], ['manage'], ['read', 'write', 'manage']] as $abilities) {
            tokenCall($this, tokenFor($team['owner'], $abilities), 'PUT', $uri, ['enabled' => true])->assertForbidden()->assertJsonPath('code', 'session_required');
        }

        tokenWithSessionCall($this, $team['owner'], tokenFor($team['owner'], ['manage']), 'PUT', $uri, ['enabled' => true])->assertForbidden();

        expect(photoEntryStored($team['workspace']))->toBeFalse();
    });
});

describe('the token API', function (): void {
    it('does not show the consent of a workspace', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();
        inventoryCall($this, $team['owner'], 'PUT', photoEntryUri($team['workspace']), ['enabled' => true])->assertOk();

        $response = tokenCall($this, tokenFor($team['owner'], ['read']), 'GET', '/api/v1/workspaces')->assertOk();

        expect($response->getContent())->not->toContain('photo_entry');
    });

    it('cannot change it by renaming a workspace', function (): void {
        photoEntryOffered();
        $team = inventoryTeam();

        inventoryCall($this, $team['owner'], 'PATCH', "/api/v1/workspaces/{$team['workspace']->ulid}", ['name' => 'Renamed', 'photo_entry_enabled' => true])->assertOk();

        expect($team['workspace']->fresh()->name)->toBe('Renamed')->and(photoEntryStored($team['workspace']))->toBeFalse();
    });
});
