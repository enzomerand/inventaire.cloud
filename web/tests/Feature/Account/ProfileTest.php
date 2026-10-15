<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('changing the name and the language', function (): void {
    it('changes the name and describes the account', function (): void {
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['name' => '  Ada Lovelace '], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $user->id, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'locale' => null, 'is_admin' => false]]);

        expect($user->fresh()->name)->toBe('Ada Lovelace');
    });

    it('changes the language of the account and puts it in the locale cookie, like the language switcher', function (): void {
        $user = User::factory()->speaking('fr')->create();

        $this->actingAs($user)->patchJson('/api/v1/me', ['locale' => 'en'], frontend())
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertCookie('locale', 'en', false);

        expect($user->fresh()->locale)->toBe('en');
    });

    it('changes both at once', function (): void {
        $user = User::factory()->speaking('fr')->create(['name' => 'Ada']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['name' => 'Grace', 'locale' => 'en'], frontend())
            ->assertOk()
            ->assertCookie('locale', 'en', false);

        expect($user->fresh()->name)->toBe('Grace')->and($user->fresh()->locale)->toBe('en');
    });

    it('sets no cookie when the language is not being changed', function (): void {
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/me', ['name' => 'Grace'], frontend())
            ->assertOk()
            ->assertCookieMissing('locale');
    });

    it('leaves what is not sent as it is', function (): void {
        $user = User::factory()->speaking('en')->create(['name' => 'Ada']);

        $this->actingAs($user)->patchJson('/api/v1/me', [], frontend())->assertOk();

        expect($user->fresh()->name)->toBe('Ada')->and($user->fresh()->locale)->toBe('en');
    });

    it('refuses a name that is empty, blank, too long or not text', function (mixed $name): void {
        $user = User::factory()->create(['name' => 'Ada']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['name' => $name], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        expect($user->fresh()->name)->toBe('Ada');
    })->with([
        'empty' => [''],
        'blank' => ['   '],
        'null' => [null],
        'too long' => [str_repeat('a', 256)],
        'a number' => [12],
    ]);

    it('accepts a name of 255 characters', function (): void {
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/me', ['name' => str_repeat('é', 255)], frontend())->assertOk();
    });

    it('refuses a language the application is not translated into', function (mixed $locale): void {
        $user = User::factory()->speaking('fr')->create();

        $this->actingAs($user)->patchJson('/api/v1/me', ['locale' => $locale], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale')
            ->assertCookieMissing('locale');

        expect($user->fresh()->locale)->toBe('fr');
    })->with(['unknown' => ['xx'], 'empty' => [''], 'null' => [null], 'a list' => [['en']]]);

    it('does not let the account be promoted, or its address changed', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['is_admin' => true, 'email' => 'eve@example.com', 'remember_token' => 'mine'], frontend())->assertOk();

        $fresh = $user->fresh();
        expect($fresh->is_admin)->toBeFalse()->and($fresh->email)->toBe('ada@example.com')->and($fresh->remember_token)->not->toBe('mine');
    });

    it('answers 401 in JSON to somebody who is not signed in', function (): void {
        $this->patchJson('/api/v1/me', ['name' => 'Eve'], frontend())->assertUnauthorized();
    });

    it('is written in the language of the request', function (): void {
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/me', ['locale' => 'xx'], frontend(['Accept-Language' => 'en']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.locale.0', 'The selected locale is invalid.');
    });
});
