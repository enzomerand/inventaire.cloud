<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

describe('the pace of a token', function (): void {
    it('is counted for each token, and tells how many requests are left', function (): void {
        config(['inventories.api_token_requests_per_minute' => 3]);
        $token = tokenFor(inventoryPerson('Ada'), ['read']);

        $first = tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        $second = tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        $third = tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();

        expect($first->headers->get('X-RateLimit-Limit'))->toBe('3')->and($first->headers->get('X-RateLimit-Remaining'))->toBe('2')
            ->and($second->headers->get('X-RateLimit-Remaining'))->toBe('1')
            ->and($third->headers->get('X-RateLimit-Remaining'))->toBe('0');
    });

    it('answers a request past the limit with a 429 in JSON, with the time to wait', function (): void {
        config(['inventories.api_token_requests_per_minute' => 2]);
        $token = tokenFor(inventoryPerson('Ada'), ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        $refused = tokenCall($this, $token, 'GET', '/api/v1/me', headers: ['Accept-Language' => 'en'])->assertTooManyRequests();

        $refused->assertExactJson(['message' => 'Too many attempts. Please wait a moment before trying again.', 'code' => 'too_many_requests']);

        expect($refused->headers->get('Content-Type'))->toBe('application/json')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 60)
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('2')
            ->and($refused->headers->get('X-RateLimit-Remaining'))->toBe('0');
    });

    it('answers in the language of the request, and in JSON whatever the client accepts', function (): void {
        config(['inventories.api_token_requests_per_minute' => 1]);
        $token = tokenFor(inventoryPerson('Ada'), ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();

        tokenCall($this, $token, 'GET', '/api/v1/me', headers: ['Accept' => 'text/html', 'Accept-Language' => 'fr'])
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Trop de tentatives. Patientez un instant avant de réessayer.', 'code' => 'too_many_requests']);
    });

    it('counts every token apart: another token of the same person, and the token of another, have their own', function (): void {
        config(['inventories.api_token_requests_per_minute' => 2]);
        $ada = inventoryPerson('Ada');
        $first = tokenFor($ada, ['read']);
        $second = tokenFor($ada, ['read']);
        $graces = tokenFor(inventoryPerson('Grace'), ['read']);

        tokenCall($this, $first, 'GET', '/api/v1/me')->assertOk();
        tokenCall($this, $first, 'GET', '/api/v1/me')->assertOk();
        tokenCall($this, $first, 'GET', '/api/v1/me')->assertTooManyRequests();

        tokenCall($this, $second, 'GET', '/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        tokenCall($this, $graces, 'GET', '/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        tokenCall($this, $first, 'GET', '/api/v1/me')->assertTooManyRequests();
    });

    it('counts every request, whichever route it goes to and whether or not the token may do it', function (): void {
        config(['inventories.api_token_requests_per_minute' => 3]);
        $world = inventoryTeam();
        $inventory = inventoryIn($world['workspace']);
        $token = tokenFor($world['owner'], ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/workspaces')->assertOk();
        // Refused for lack of the ability: it still used a request.
        tokenCall($this, $token, 'POST', "/api/v1/inventories/{$inventory->ulid}/items")->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        tokenCall($this, $token, 'GET', "/api/v1/inventories/{$inventory->ulid}")->assertOk();
        tokenCall($this, $token, 'GET', '/api/v1/field-types')->assertTooManyRequests();
    });

    it('starts again a minute later', function (): void {
        config(['inventories.api_token_requests_per_minute' => 1]);
        $token = tokenFor(inventoryPerson('Ada'), ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        tokenCall($this, $token, 'GET', '/api/v1/me')->assertTooManyRequests();

        $this->travel(61)->seconds();

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
    });

    it('does not count the web front, which has a session and no token', function (): void {
        config(['inventories.api_token_requests_per_minute' => 2]);
        $user = inventoryPerson('Ada');

        foreach (range(1, 6) as $ignored) {
            $response = inventoryCall($this, $user, 'GET', '/api/v1/me')->assertOk();

            expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
        }
    });

    it('does not count the web front of a person whose token is used up', function (): void {
        config(['inventories.api_token_requests_per_minute' => 1]);
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['read']);

        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk();
        tokenCall($this, $token, 'GET', '/api/v1/me')->assertTooManyRequests();

        inventoryCall($this, $user, 'GET', '/api/v1/me')->assertOk();
    });

    it('counts a request that sends a session and a token too, for the person, so that sending both is no way around the pace', function (): void {
        config(['inventories.api_token_requests_per_minute' => 2]);
        $user = inventoryPerson('Ada');
        $token = tokenFor($user, ['read']);

        $first = tokenWithSessionCall($this, $user, $token, 'GET', '/api/v1/me')->assertForbidden()->assertJsonPath('code', 'token_with_session');
        tokenWithSessionCall($this, $user, $token, 'GET', '/api/v1/me')->assertForbidden()->assertJsonPath('code', 'token_with_session');
        $refused = tokenWithSessionCall($this, $user, $token, 'GET', '/api/v1/me')->assertTooManyRequests();

        expect($first->headers->get('X-RateLimit-Limit'))->toBe('2')->and($first->headers->get('X-RateLimit-Remaining'))->toBe('1')
            ->and($refused->json('code'))->toBe('too_many_requests')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 60);

        // Another text sent as a token gains nothing: the session signs the person in, and the person is what is counted.
        tokenWithSessionCall($this, $user, '1|another-text', 'GET', '/api/v1/me')->assertTooManyRequests();

        // The token used on its own has its own count.
        tokenCall($this, $token, 'GET', '/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
    });

    it('is 120 requests a minute by default', function (): void {
        expect(config('inventories.api_token_requests_per_minute'))->toBe(120);
    });

    it('comes on top of the limits of the endpoints: the stricter one answers', function (): void {
        config(['inventories.api_token_requests_per_minute' => 10, 'workspaces.create_per_hour' => 1]);
        $token = tokenFor(inventoryPerson('Ada'), ['manage']);

        tokenCall($this, $token, 'POST', '/api/v1/workspaces', ['name' => 'One'])->assertCreated();
        $refused = tokenCall($this, $token, 'POST', '/api/v1/workspaces', ['name' => 'Two'])->assertTooManyRequests();

        expect($refused->json('code'))->toBe('too_many_requests')
            ->and((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 3600)
            ->and(DB::table('workspaces')->where('name', 'Two')->exists())->toBeFalse();
    });
});

describe('a 429 of the API', function (): void {
    it('is JSON with a code whichever limit it is, the public ones included', function (): void {
        config(['inventories.templates_per_minute' => 1]);

        $this->getJson('/api/v1/templates')->assertOk();
        $refused = $this->getJson('/api/v1/templates')->assertTooManyRequests();

        expect($refused->json())->toBe(['message' => 'Too many attempts. Please wait a moment before trying again.', 'code' => 'too_many_requests'])
            ->and($refused->headers->has('Retry-After'))->toBeTrue()
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('1');
    });

    it('does not change the pages of the site, which answer in HTML', function (): void {
        config(['inventories.public_pages_per_minute' => 1]);

        $this->get('/templates')->assertOk();
        $refused = $this->get('/templates')->assertStatus(429);

        expect((string) $refused->headers->get('Content-Type'))->toContain('text/html')->and($refused->headers->has('Retry-After'))->toBeTrue();
    });
});

describe('the tokens that expired', function (): void {
    it('are deleted a day after they expired, every day', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'sanctum:prune-expired'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->command)->toContain('--hours=24')
            ->and($events->first()->expression)->toBe('0 0 * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            // The lock of a run that was killed is forgotten after 30 minutes: the default is a day, a day without pruning.
            ->and($events->first()->expiresAt)->toBe(30);
    });

    it('are the only ones the pruning deletes', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $user->createToken('long gone', ['read'], now()->subDays(2));
        $user->createToken('lapsed an hour ago', ['read'], now()->subHour());
        $user->createToken('valid', ['read'], now()->addDay());
        $user->createToken('for ever', ['read']);

        $this->artisan('sanctum:prune-expired --hours=24')->assertSuccessful();

        expect(DB::table('personal_access_tokens')->orderBy('id')->pluck('name')->all())->toBe(['lapsed an hour ago', 'valid', 'for ever']);
    });
});
