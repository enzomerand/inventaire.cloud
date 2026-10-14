<?php

declare(strict_types=1);

use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The framework skips the CSRF check when the environment is "testing", which is why the rest of the suite does
    // not send a token. These tests need the real check: they run as the application does everywhere else.
    $this->app['env'] = 'production';
});

describe('a session-changing call from the web front', function (): void {
    it('is refused with 419 when it carries no XSRF token', function (string $uri, array $data): void {
        $this->postJson($uri, $data, frontend())->assertStatus(419);

        expect(sentMails())->toBe([])->and(LoginCode::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
        $this->assertGuest('web');
    })->with([
        'asking for a code' => ['/api/v1/auth/code', ['email' => 'ada@example.com']],
        'typing a code back' => ['/api/v1/auth/verify', ['email' => 'ada@example.com', 'code' => '123456', 'name' => 'Ada']],
    ]);

    it('is refused with 419 when the token is not the one of the session', function (): void {
        $this->withSession(['_token' => 'the-real-token'])
            ->postJson('/api/v1/auth/code', ['email' => 'ada@example.com'], frontend(['X-CSRF-TOKEN' => 'a-forged-token']))
            ->assertStatus(419);

        expect(sentMails())->toBe([]);
    });

    it('does not let a forged call sign somebody out', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/logout', [], frontend())->assertStatus(419);

        $this->assertAuthenticatedAs($user, 'web');
    });

    it('goes through with the token of the session, in the header the front sends', function (): void {
        $this->withSession(['_token' => 'the-real-token'])
            ->postJson('/api/v1/auth/code', ['email' => 'ada@example.com'], frontend(['X-CSRF-TOKEN' => 'the-real-token']))
            ->assertOk();

        expect(sentMails())->toHaveCount(1);
    });
});
