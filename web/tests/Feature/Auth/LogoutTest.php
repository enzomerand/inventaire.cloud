<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('signing out', function (): void {
    it('ends the session and answers 204', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/logout', [], frontend())->assertNoContent();

        $this->assertGuest('web');
    });

    it('turns the remember token over, so that stolen cookies stop working', function (): void {
        $user = User::factory()->create(['remember_token' => 'old-token']);

        $this->actingAs($user)->postJson('/api/v1/auth/logout', [], frontend())->assertNoContent();

        expect($user->fresh()->remember_token)->not->toBe('old-token');
    });

    it('answers 401 in JSON to somebody who is not signed in', function (): void {
        $this->postJson('/api/v1/auth/logout', [], frontend())->assertUnauthorized();
        $this->call('POST', '/api/v1/auth/logout', server: ['HTTP_REFERER' => 'http://localhost/'])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    });

    it('is the web front only', function (): void {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/auth/logout')
            ->assertForbidden()
            ->assertJsonPath('code', 'not_stateful');
    });
});
