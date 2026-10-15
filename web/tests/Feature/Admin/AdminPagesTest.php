<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

dataset('admin pages', [
    'users' => ['app.admin.users', '/app/admin/users'],
    'settings' => ['app.admin.settings', '/app/admin/settings'],
]);

describe('the "admin" gate', function (): void {
    it('lets an administrator through and nobody else', function (): void {
        expect(Gate::forUser(User::factory()->admin()->create())->allows('admin'))->toBeTrue()
            ->and(Gate::forUser(User::factory()->create())->allows('admin'))->toBeFalse();
    });

    it('follows the account as stored: demoting takes effect at once', function (): void {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/app/admin/users')->assertOk();

        User::query()->whereKey($user->id)->update(['is_admin' => false]);

        $this->actingAs($user->fresh())->get('/app/admin/users')->assertForbidden();
    });
});

describe('the administration pages', function (): void {
    it('are at /app/admin', function (string $route, string $path): void {
        expect(route($route, absolute: false))->toBe($path);
    })->with('admin pages');

    it('send a visitor to the login page', function (string $route): void {
        $this->get(route($route))->assertRedirect(route('login'));
    })->with('admin pages');

    it('are forbidden to an account that does not administer the instance', function (string $route): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->get(route($route))->assertForbidden();
    })->with('admin pages');

    it('open for an administrator, with the tabs of the administration', function (string $route): void {
        $this->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
            ->get(route($route), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('Administration')
            ->assertSee('href="'.route('app.admin.users').'"', false)
            ->assertSee('href="'.route('app.admin.settings').'"', false);
    })->with('admin pages');

    it('give the users page its script and its endpoint', function (): void {
        $this->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
            ->get(route('app.admin.users'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="adminUsers"', false)
            ->assertSee('data-url="/api/v1/admin/users"', false)
            ->assertSee('Search by name or email address')
            ->assertSee('New account')
            ->assertSee('Create the account')
            // The scripts find their messages in the page.
            ->assertSee('admin.users.confirm_delete', false)
            ->assertSee('admin.users.notice.created', false);
    });

    it('mark the tab that is open', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();

        $this->actingAs($admin)->get(route('app.admin.users'))->assertOk()
            ->assertSeeInOrder(['nav-link active', 'aria-current="page"', 'Users'], false);
        $this->actingAs($admin)->get(route('app.admin.settings'))->assertOk()
            ->assertSeeInOrder(['nav-link', 'nav-link active', 'aria-current="page"', 'Settings'], false);
    });

    it('are translated', function (): void {
        $this->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
            ->get(route('app.admin.users'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Utilisateurs')
            ->assertSee('Paramètres');
    });

    it('check that the session was not revoked, like the other pages of the space', function (string $route): void {
        $middleware = Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')->toContain(AuthenticateSession::class)->toContain('can:admin');
    })->with('admin pages');
});

describe('the entry of the account menu', function (): void {
    it('is shown to an administrator', function (): void {
        $this->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
            ->get(route('app'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('href="'.route('app.admin.users').'"', false)
            ->assertSee('Administration');
    });

    it('is not shown to anybody else', function (): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())
            ->get(route('app'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertDontSee('/app/admin')
            ->assertDontSee('Administration');
    });

    it('is not shown to a visitor either', function (): void {
        $this->get(route('home'))->assertOk()->assertDontSee('/app/admin');
    });
});
