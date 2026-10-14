<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('the users table', function (): void {
    it('goes back and forth between the passwordless and the former schema', function (): void {
        $migration = require database_path('migrations/2026_10_14_150000_make_users_passwordless.php');

        $migration->down();

        try {
            expect(Schema::hasColumn('users', 'password'))->toBeTrue()
                ->and(Schema::hasColumn('users', 'locale'))->toBeFalse()
                ->and(Schema::hasColumn('users', 'is_admin'))->toBeFalse()
                ->and(Schema::hasTable('password_reset_tokens'))->toBeTrue();
        } finally {
            $migration->up();
        }

        expect(Schema::hasColumn('users', 'password'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'is_admin'))->toBeTrue()
            ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse();
    });
});

describe('the user model', function (): void {
    it('does not let a request promote itself: is_admin is not mass-assignable', function (): void {
        $user = User::query()->create(['name' => 'Eve', 'email' => 'eve@example.com', 'is_admin' => true]);

        expect($user->fresh()->is_admin)->toBeFalse();
    });

    it('writes mails to the account language', function (): void {
        expect(User::factory()->speaking('en')->make()->preferredLocale())->toBe('en')
            ->and(User::factory()->make()->preferredLocale())->toBeNull();
    });

    it('never shows the remember token', function (): void {
        expect(User::factory()->create()->toArray())->not->toHaveKey('remember_token')->not->toHaveKey('password');
    });
});

describe('the account language', function (): void {
    it('is saved on the account by the language switcher, together with the cookie', function (): void {
        $user = User::factory()->speaking('fr')->create();

        $this->actingAs($user)->putJson(route('locale.update'), ['locale' => 'en'])
            ->assertNoContent()
            ->assertCookie('locale', 'en', false);

        expect($user->fresh()->locale)->toBe('en');
    });

    it('leaves the account alone when the language is refused', function (): void {
        $user = User::factory()->speaking('fr')->create();

        $this->actingAs($user)->putJson(route('locale.update'), ['locale' => 'xx'])->assertUnprocessable();

        expect($user->fresh()->locale)->toBe('fr');
    });

    it('still works for a visitor who is not signed in', function (): void {
        $this->putJson(route('locale.update'), ['locale' => 'en'])
            ->assertNoContent()
            ->assertCookie('locale', 'en', false);
    });

    it('is exposed to API clients with the administrator flag', function (): void {
        $user = User::factory()->speaking('en')->admin()->create();

        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.locale', 'en')->assertJsonPath('data.is_admin', true);
    });
});
