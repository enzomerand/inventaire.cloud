<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The framework skips the CSRF check when the environment is "testing", which is why the rest of the suite does
    // not send a token. These tests need the real check: they run as the application does everywhere else.
    $this->app['env'] = 'production';
});

describe('a change from the administration made by a forged call', function (): void {
    it('is refused with 419 when it carries no XSRF token, and changes nothing', function (string $method, string $uri, array $data): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        $other = User::factory()->admin()->withPersonalWorkspace()->create();
        $victim = User::factory()->withPersonalWorkspace()->create();

        $this->actingAs($admin)->json($method, str_replace(['{admin}', '{victim}'], [(string) $other->id, (string) $victim->id], $uri), $data, frontend())->assertStatus(419);

        expect(User::query()->count())->toBe(3)
            ->and($other->fresh()?->is_admin)->toBeTrue()
            ->and($victim->fresh()?->is_admin)->toBeFalse()
            ->and(app(InstanceSettings::class)->registrationOpen())->toBeTrue()
            ->and(DB::table('settings')->count())->toBe(0)
            ->and(sentMails())->toBe([]);
    })->with([
        'creating an account' => ['POST', '/api/v1/admin/users', ['email' => 'new@example.com', 'name' => 'New']],
        'making an administrator' => ['PATCH', '/api/v1/admin/users/{victim}', ['is_admin' => true]],
        'removing an administrator' => ['PATCH', '/api/v1/admin/users/{admin}', ['is_admin' => false]],
        'deleting an account' => ['DELETE', '/api/v1/admin/users/{victim}', []],
        'closing registration' => ['PUT', '/api/v1/admin/settings', ['registration_open' => false]],
    ]);

    it('goes through with the token of the session, in the header the front sends', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();

        $this->withSession(['_token' => 'the-real-token'])->actingAs($admin)
            ->putJson('/api/v1/admin/settings', ['registration_open' => false], frontend(['X-CSRF-TOKEN' => 'the-real-token']))
            ->assertOk();

        expect(DB::table('settings')->count())->toBe(1);
    });
});
