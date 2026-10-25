<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Settings\InstanceSettings;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 * The workspaces page and the photo entry: the owner of a workspace gets a switch in its row when the instance offers the feature. The list
 * endpoint is a token route and does not carry the consent, so the page renders it (`data-photo-entry`): the ULIDs of the workspaces the
 * person owns, each with its consent, or null when the instance does not offer it.
 */

beforeEach(function (): void {
    config(['services.anthropic.key' => null]);
});

/**
 * Makes the instance offer the photo entry: the administrator turned it on and there is an API key.
 */
function workspacePageOffersPhotoEntry(): void
{
    app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => true]);
    config(['services.anthropic.key' => 'sk-ant-test-secret']);
}

/**
 * The workspaces page as the user reads it.
 */
function workspacePage(object $test, User $user, string $language = 'en'): TestResponse
{
    return $test->actingAs($user)->get(route('app.workspaces'), ['Accept-Language' => $language])->assertOk();
}

/**
 * The consent the page renders for the owned workspaces, decoded as the browser decodes it: null when the page says the instance does not
 * offer the feature.
 *
 * @return array<string, bool>|null
 */
function workspacePageConsents(TestResponse $page): ?array
{
    preg_match('/data-photo-entry="([^"]*)"/', $page->getContent(), $match);

    expect($match)->not->toBe([], 'the page has a data-photo-entry attribute');

    return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A workspace the person owns, with the consent of its owner.
 */
function ownedWorkspace(User $owner, string $name, bool $consent): Workspace
{
    $workspace = app(Workspaces::class)->create($owner, $name);
    $workspace->forceFill(['photo_entry_enabled' => $consent])->save();

    return $workspace;
}

describe('when the instance does not offer the photo entry', function (): void {
    it('renders null: the page shows no switch', function (?bool $switch, ?string $key): void {
        if ($switch !== null) {
            app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => $switch]);
        }

        config(['services.anthropic.key' => $key]);
        $user = User::factory()->withPersonalWorkspace()->create();
        ownedWorkspace($user, 'Shop', true);

        $page = workspacePage($this, $user);

        expect(workspacePageConsents($page))->toBeNull();
        $page->assertSee('data-photo-entry="null"', false);
    })->with([
        'the administrator never chose' => [null, 'sk-ant-test-secret'],
        'the administrator has it off' => [false, 'sk-ant-test-secret'],
        'there is no key' => [true, null],
        'the key is blank' => [true, '   '],
        'nothing is set' => [null, null],
    ]);
});

describe('when the instance offers the photo entry', function (): void {
    beforeEach(function (): void {
        workspacePageOffersPhotoEntry();
    });

    it('renders the consent of each workspace the person owns, by ULID', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $personal = $user->workspaces()->firstOrFail();
        $shop = ownedWorkspace($user, 'Shop', true);
        $depot = ownedWorkspace($user, 'Depot', false);

        $consents = workspacePageConsents(workspacePage($this, $user));

        expect($consents)->toBe([$personal->ulid => false, $shop->ulid => true, $depot->ulid => false]);
    });

    it('shows the consent as it is stored now', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $shop = ownedWorkspace($user, 'Shop', false);

        expect(workspacePageConsents(workspacePage($this, $user->fresh()))[$shop->ulid])->toBeFalse();

        $shop->forceFill(['photo_entry_enabled' => true])->save();
        $this->flushSession();

        expect(workspacePageConsents(workspacePage($this, $user->fresh()))[$shop->ulid])->toBeTrue();
    });

    it('leaves out the workspaces the person only edits or reads: the owner alone chooses', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = User::factory()->withPersonalWorkspace()->create();
        $mine = ownedWorkspace($user, 'Mine', true);
        $edited = ownedWorkspace($other, 'Edited', true);
        $read = ownedWorkspace($other, 'Read', true);
        $edited->members()->attach($user->id, ['role' => WorkspaceRole::Editor->value]);
        $read->members()->attach($user->id, ['role' => WorkspaceRole::Viewer->value]);

        $consents = workspacePageConsents(workspacePage($this, $user->fresh()));

        expect($consents)->toHaveKey($mine->ulid)
            ->and($consents)->not->toHaveKey($edited->ulid)
            ->and($consents)->not->toHaveKey($read->ulid)
            ->and($consents)->toHaveCount(2);
    });

    it('says nothing of the workspaces of other people', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $stranger = User::factory()->withPersonalWorkspace()->create();
        $theirs = ownedWorkspace($stranger, 'Theirs', true);

        $page = workspacePage($this, $user);

        expect(workspacePageConsents($page))->not->toHaveKey($theirs->ulid);
        $page->assertDontSee($theirs->ulid)->assertDontSee('Theirs');
    });

    it('renders an empty object, not a list, for somebody who owns nothing', function (): void {
        $user = User::factory()->create();

        workspacePage($this, $user)->assertSee('data-photo-entry="{}"', false);
    });

    it('gives the address of the switch, with the place of the ULID', function (): void {
        workspacePage($this, User::factory()->withPersonalWorkspace()->create())
            ->assertSee('data-photo-entry-url="/api/v1/workspaces/__id__/photo-entry"', false);
    });

    it('has the switch in the row of a workspace, with its note', function (): void {
        workspacePage($this, User::factory()->withPersonalWorkspace()->create())
            ->assertSee('Photo entry (AI)')
            ->assertSee('Photos sent by the editors are read by Claude (Anthropic), then deleted.')
            ->assertSee('@change="togglePhotoEntry(item, $event)"', false)
            ->assertSee('x-show="canPhotoEntry(item)"', false);
    });

    it('never writes the key in the page', function (): void {
        workspacePage($this, User::factory()->withPersonalWorkspace()->create())->assertDontSee('sk-ant-test-secret');
    });

    it('is translated', function (): void {
        workspacePage($this, User::factory()->withPersonalWorkspace()->create(), 'fr')
            ->assertSee('Saisie par photos (IA)')
            ->assertSee('Les photos envoyées par les éditeurs sont lues par Claude (Anthropic), puis supprimées.');
    });

    it('gives the scripts the messages the switch needs', function (): void {
        workspacePage($this, User::factory()->withPersonalWorkspace()->create())
            ->assertSee('workspaces.photo_entry_named', false)
            ->assertSee('workspaces.notice.photo_entry_on', false)
            ->assertSee('workspaces.notice.photo_entry_off', false);
    });
});

describe('the list endpoint', function (): void {
    it('does not carry the consent: it is a token route', function (): void {
        workspacePageOffersPhotoEntry();
        $user = User::factory()->withPersonalWorkspace()->create();
        ownedWorkspace($user, 'Shop', true);

        $response = $this->actingAs($user)->getJson('/api/v1/workspaces', frontend())->assertOk();

        expect($response->getContent())->not->toContain('photo_entry');
    });
});

it('still sends a visitor to the login page', function (): void {
    $this->get(route('app.workspaces'))->assertRedirect(route('login'));
});
