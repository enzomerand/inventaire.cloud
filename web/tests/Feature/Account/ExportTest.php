<?php

declare(strict_types=1);

use App\Auth\AccountExport;
use App\Models\User;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('the export of the account', function (): void {
    it('is a JSON file to download, named after the day', function (): void {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 30));

        $response = $this->actingAs(User::factory()->create())->get('/api/v1/me/export', frontend())->assertOk();

        expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=inventaire-export-2026-10-15.json')
            ->and($response->headers->get('Content-Type'))->toStartWith('application/json')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        expect(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR))->toBeArray();
    });

    it('holds the profile of the account and nothing secret', function (): void {
        $user = User::factory()->speaking('en')->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $export = $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->assertOk()->json();

        expect($export)->toHaveKeys(['format', 'version', 'exported_at', 'user', 'workspaces'])
            ->and($export['format'])->toBe(AccountExport::FORMAT)
            ->and($export['version'])->toBe(AccountExport::VERSION)
            ->and($export['user'])->toBe([
                'id' => $user->id,
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'locale' => 'en',
                'is_admin' => false,
                'email_verified_at' => $user->email_verified_at->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'updated_at' => $user->updated_at->toIso8601String(),
            ]);

        $body = (string) json_encode($export);
        expect($body)->not->toContain((string) $user->remember_token)->not->toContain('remember_token')->not->toContain('password');
    });

    it('holds the workspaces with the role in each, ready to receive inventories', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($other, 'Hers');
        $shared->members()->attach($user->id, ['role' => WorkspaceRole::Viewer->value]);
        $personal = $user->workspaces()->orderBy('workspace_user.id')->firstOrFail();

        $workspaces = $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->assertOk()->json('workspaces');

        expect($workspaces)->toBe([
            ['id' => $personal->ulid, 'name' => 'Espace personnel', 'role' => 'owner', 'is_current' => true, 'created_at' => $personal->created_at->toIso8601String(), 'updated_at' => $personal->updated_at->toIso8601String(), 'inventories' => []],
            ['id' => $shared->ulid, 'name' => 'Hers', 'role' => 'viewer', 'is_current' => false, 'created_at' => $shared->created_at->toIso8601String(), 'updated_at' => $shared->updated_at->toIso8601String(), 'inventories' => []],
        ]);
    });

    it('never holds the data of somebody else', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $other = User::factory()->withPersonalWorkspace()->create(['email' => 'grace@example.com', 'name' => 'Grace Hopper']);
        app(Workspaces::class)->create($other, 'Her secret workspace');

        $body = (string) $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->getContent();

        expect($body)->not->toContain('grace@example.com')->not->toContain('Grace Hopper')->not->toContain('Her secret workspace');
    });

    it('keeps accents and slashes readable', function (): void {
        $user = User::factory()->create(['name' => 'Zoë / Œuvre']);

        $body = (string) $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->getContent();

        expect($body)->toContain('Zoë / Œuvre');
    });

    it('is for somebody who is signed in', function (): void {
        $this->getJson('/api/v1/me/export', frontend())->assertUnauthorized();
    });

    it('is throttled', function (): void {
        $user = User::factory()->create();
        $limit = config()->integer('auth.account_export.attempts');

        foreach (range(1, $limit) as $_) {
            $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->assertOk();
        }

        $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->assertTooManyRequests();

        // The allowance is the person's own: somebody else is not held back.
        $this->flushSession();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/me/export', frontend())->assertOk();
    });
});
