<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/versions.php';

/*
 * The export of the account (GDPR) counts, in each workspace, the official versions the person froze.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

describe('the export of the account', function (): void {
    it('counts, in each workspace, the versions the person froze that still exist, and nobody else\'s', function (): void {
        Queue::fake();
        $case = versionTableCase();
        $personal = $case['team']['editor']->workspaces()->orderBy('workspace_user.id')->firstOrFail();
        $mine = inventoryIn($personal, 'Mine');
        versionRun(versionFreeze($case['inventory'], $case['team']['editor']));
        $gone = versionRun(versionFreeze($case['inventory'], $case['team']['editor']));
        versionRun(versionFreeze($case['inventory'], $case['team']['owner']));
        versionFreeze($mine, $case['team']['editor']);

        $count = fn (): array => collect(inventoryCall($this, $case['team']['editor'], 'GET', '/api/v1/me/export')->assertOk()->json('workspaces'))->pluck('official_versions_created', 'id')->all();

        expect($count())->toBe([$personal->ulid => 1, $case['team']['workspace']->ulid => 2]);

        inventoryCall($this, $case['team']['owner'], 'DELETE', "/api/v1/inventories/{$case['inventory']->ulid}/versions/{$gone->ulid}")->assertNoContent();

        expect($count())->toBe([$personal->ulid => 1, $case['team']['workspace']->ulid => 1])
            ->and(array_column(inventoryCall($this, $case['team']['viewer'], 'GET', '/api/v1/me/export')->json('workspaces'), 'official_versions_created'))->toBe([0, 0]);
    });

    it('is a field of the workspaces, after the files, and keeps the version of the document', function (): void {
        $person = inventoryPerson('Ada');

        $export = inventoryCall($this, $person, 'GET', '/api/v1/me/export')->assertOk()->json();

        expect($export['version'])->toBe(1)
            ->and(array_keys($export['workspaces'][0]))->toBe(['id', 'name', 'role', 'is_current', 'created_at', 'updated_at', 'revisions_written', 'files_uploaded', 'official_versions_created', 'inventories']);
    });
});
