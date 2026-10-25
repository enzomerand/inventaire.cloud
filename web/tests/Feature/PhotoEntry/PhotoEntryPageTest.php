<?php

declare(strict_types=1);

use App\Settings\InstanceSettings;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * What the page of an inventory says about adding an item from photos: the button, its dialog and the endpoint of the analyses are in
 * the markup only when the instance (the administrator's switch and an API key), the workspace (its owner's consent) and the role
 * (somebody who may add items) all allow it. The scripts, and the API, check again: this is the first of the doors, not the only one.
 */

/**
 * Turns the photo entry on for the workspace of a team (every condition), then lets a test undo one.
 *
 * @param  array<string, mixed>  $team  {@see inventoryTeam()}
 */
function photoEntryOn(array $team, bool $switch = true, ?string $key = 'sk-ant-test-secret', bool $consent = true): void
{
    app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => $switch]);
    config(['services.anthropic.key' => $key]);
    $team['workspace']->forceFill(['photo_entry_enabled' => $consent])->save();
}

/**
 * The page of an inventory of the team, seen by one of its members, in English unless told otherwise.
 *
 * @param  array<string, mixed>  $team
 */
function photoEntryPage(object $test, array $team, string $role, string $language = 'en'): TestResponse
{
    $inventory = inventoryIn($team['workspace']->fresh());

    return $test->actingAs($team[$role])->get(
        route('app.inventory', ['workspace' => $team['workspace']->ulid, 'inventory' => $inventory->ulid]),
        ['Accept-Language' => $language],
    )->assertOk();
}

/**
 * The value of an attribute of the markup, decoded like the browser decodes it.
 */
function photoEntryAttribute(TestResponse $page, string $name): ?string
{
    if (preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $page->getContent(), $match) !== 1) {
        return null;
    }

    return html_entity_decode($match[1], ENT_QUOTES);
}

/**
 * Asserts the page offers nothing of the photo entry: a flag that says no, and neither the button, nor the dialog, nor the endpoint.
 */
function assertNoPhotoEntry(TestResponse $page): void
{
    expect(photoEntryAttribute($page, 'data-photo-entry'))->toBe('0')
        ->and(photoEntryAttribute($page, 'data-photo-entry-url'))->toBeNull()
        ->and(photoEntryAttribute($page, 'data-photo-entry-max'))->toBeNull();

    $page->assertDontSee('Add from photos')
        ->assertDontSee('x-ref="photoModal"', false)
        ->assertDontSee('photo-analyses', false);
}

describe('when the instance, the workspace and the role allow it', function (): void {
    it('offers the button and the dialog to the people who may add items, with the endpoint of the analyses', function (string $role): void {
        $team = inventoryTeam();
        photoEntryOn($team);

        $page = photoEntryPage($this, $team, $role);

        expect(photoEntryAttribute($page, 'data-photo-entry'))->toBe('1')
            ->and(photoEntryAttribute($page, 'data-photo-entry-url'))->toMatch('#^/api/v1/inventories/[0-9a-z]{26}/photo-analyses$#')
            ->and(photoEntryAttribute($page, 'data-photo-entry-max'))->toBe('5');

        $page->assertSee('Add from photos')
            ->assertSee('x-ref="photoModal"', false)
            ->assertSee('@click="openPhotoEntry"', false)
            ->assertSee('The photos are sent to Anthropic (Claude) to be read, then deleted.');
    })->with([
        'an owner' => ['owner'],
        'an editor' => ['editor'],
    ]);

    it('lets the phone choose between the camera and the gallery: images, several, and no capture', function (): void {
        $team = inventoryTeam();
        photoEntryOn($team);

        $page = photoEntryPage($this, $team, 'owner');

        $page->assertSee('id="photo-entry-pick" accept="image/*" multiple', false);
        expect($page->getContent())->not->toMatch('/id="photo-entry-pick"[^>]*capture/');
    });

    it('takes as many photos as the configuration says', function (): void {
        $team = inventoryTeam();
        photoEntryOn($team);
        config(['inventories.max_photos_per_analysis' => 3]);

        expect(photoEntryAttribute(photoEntryPage($this, $team, 'owner'), 'data-photo-entry-max'))->toBe('3');
    });

    it('is translated', function (): void {
        $team = inventoryTeam();
        photoEntryOn($team);

        photoEntryPage($this, $team, 'owner', 'fr')
            ->assertSee('Ajouter depuis des photos')
            ->assertSee('Les photos sont envoyées à Anthropic (Claude) pour être lues, puis supprimées.');
    });
});

describe('when something does not allow it', function (): void {
    it('offers nothing to a viewer, who may not add items', function (): void {
        $team = inventoryTeam();
        photoEntryOn($team);

        assertNoPhotoEntry(photoEntryPage($this, $team, 'viewer'));
    });

    it('offers nothing while the administrator has not turned it on', function (): void {
        $team = inventoryTeam();
        photoEntryOn($team, switch: false);

        assertNoPhotoEntry(photoEntryPage($this, $team, 'owner'));
    });

    it('offers nothing when the instance has no API key', function (?string $key): void {
        $team = inventoryTeam();
        photoEntryOn($team, key: $key);

        assertNoPhotoEntry(photoEntryPage($this, $team, 'owner'));
    })->with([
        'none' => [null],
        'an empty one' => [''],
    ]);

    it('offers nothing while the owner has not turned it on for the workspace, to the owner as to an editor', function (string $role): void {
        $team = inventoryTeam();
        photoEntryOn($team, consent: false);

        assertNoPhotoEntry(photoEntryPage($this, $team, $role));
    })->with([
        'an owner' => ['owner'],
        'an editor' => ['editor'],
    ]);

    it('is a matter of each workspace: the consent of one does not open the photo entry of another', function (): void {
        $team = inventoryTeam();
        photoEntryOn($team);
        $other = app(Workspaces::class)->create($team['owner'], 'Other');

        $inventory = inventoryIn($other);
        $page = $this->actingAs($team['owner']->fresh())->get(
            route('app.inventory', ['workspace' => $other->ulid, 'inventory' => $inventory->ulid]),
        );

        $page->assertOk();
        assertNoPhotoEntry($page);
    });

    it('offers nothing by default: a fresh instance has the switch off, no key and no consent', function (): void {
        $team = inventoryTeam();

        assertNoPhotoEntry(photoEntryPage($this, $team, 'owner'));
    });
});
