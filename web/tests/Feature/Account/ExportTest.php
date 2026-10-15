<?php

declare(strict_types=1);

use App\Auth\AccountExport;
use App\Models\User;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Device;

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

    it('is named after the day in the timezone of the instance, not in UTC', function (string $zone, string $moment, string $day): void {
        config(['app.display_timezone' => $zone]);
        $this->travelTo(Carbon::parse($moment, 'UTC'));

        $response = $this->actingAs(User::factory()->create())->get('/api/v1/me/export', frontend())->assertOk();

        expect($response->headers->get('Content-Disposition'))->toBe("attachment; filename=inventaire-export-{$day}.json");
    })->with([
        'ahead of UTC, already the next day' => ['Europe/Paris', '2026-10-14 22:30:00', '2026-10-15'],
        'UTC itself' => ['UTC', '2026-10-14 22:30:00', '2026-10-14'],
        'behind UTC, still the day before' => ['America/New_York', '2026-10-15 01:30:00', '2026-10-14'],
    ]);

    it('holds the profile of the account and nothing secret', function (): void {
        $user = User::factory()->speaking('en')->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $export = $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->assertOk()->json();

        expect($export)->toHaveKeys(['format', 'version', 'exported_at', 'user', 'workspaces', 'sessions', 'tokens'])
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

describe('the access the application keeps for the account', function (): void {
    it('holds the metadata of its API tokens, and never the token', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = User::factory()->withPersonalWorkspace()->create();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 0));
        $first = $user->createToken('inventory script', ['items:read', 'items:write']);
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(9, 0));
        $second = $user->createToken('mobile');
        $second->accessToken->forceFill(['last_used_at' => now()])->save();
        $other->createToken('hers');
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 30));

        $export = $this->actingAs($user)->getJson('/api/v1/me/export', frontend())->assertOk()->json();

        expect($export['tokens'])->toBe([
            ['name' => 'inventory script', 'abilities' => ['items:read', 'items:write'], 'last_used_at' => null, 'created_at' => now()->setDate(2026, 10, 1)->setTime(8, 0)->toIso8601String()],
            ['name' => 'mobile', 'abilities' => ['*'], 'last_used_at' => now()->setDate(2026, 10, 5)->setTime(9, 0)->toIso8601String(), 'created_at' => now()->setDate(2026, 10, 5)->setTime(9, 0)->toIso8601String()],
        ]);

        $body = (string) json_encode($export);
        $hash = (string) DB::table('personal_access_tokens')->where('id', $first->accessToken->id)->value('token');
        expect($body)->not->toContain($first->plainTextToken)->not->toContain($hash)->not->toContain('hers')
            ->and(array_keys($export['tokens'][0]))->toBe(['name', 'abilities', 'last_used_at', 'created_at']);
    });

    it('has no tokens when the account has none', function (): void {
        $export = $this->actingAs(User::factory()->create())->getJson('/api/v1/me/export', frontend())->assertOk()->json();

        expect($export['tokens'])->toBe([]);
    });

    it('holds the sessions kept in the database: address, browser and last activity, never their id', function (): void {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        User::factory()->withPersonalWorkspace()->create(['email' => 'grace@example.com']);
        $laptop = new Device($this);
        $phone = new Device($this);
        $grace = new Device($this);

        // Time stands still between the sign-ins (so that the last activity of each session is known exactly) and
        // moves forward, never back: the cookies the devices keep would otherwise be dated in the past.
        $start = $this->freezeTime();
        $laptop->signIn('ada@example.com')->assertOk();
        $this->travel(30)->minutes();
        $phone->signIn('ada@example.com')->assertOk();
        $grace->signIn('grace@example.com')->assertOk();
        $this->travel(30)->minutes();

        $export = $laptop->request('GET', '/api/v1/me/export', headers: ['User-Agent' => 'Firefox/200'])->assertOk()->json();

        expect($export['sessions'])->toHaveCount(2)
            ->and(array_keys($export['sessions'][0]))->toBe(['ip_address', 'user_agent', 'last_activity'])
            ->and($export['sessions'][0]['ip_address'])->toBe('127.0.0.1')
            ->and(array_column($export['sessions'], 'last_activity'))->toBe([
                $start->copy()->addMinutes(30)->toIso8601String(),
                $start->toIso8601String(),
            ]);

        $body = (string) json_encode($export);
        expect($body)->not->toContain((string) $laptop->sessionId())->not->toContain((string) $phone->sessionId())->not->toContain((string) $grace->sessionId());
    });

    it('lists no sessions when they are not kept in the database', function (): void {
        // (The suite keeps its sessions in memory.)
        expect(config('session.driver'))->toBe('array');
        User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $laptop = new Device($this);
        $laptop->signIn('ada@example.com')->assertOk();

        $laptop->request('GET', '/api/v1/me/export')->assertOk()->assertJsonPath('sessions', []);
    });
});
