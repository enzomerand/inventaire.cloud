<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/inventories.php';
require_once __DIR__.'/../../../Support/tokens.php';

uses(RefreshDatabase::class);

/**
 * The tokens of a person as the database holds them (every column but the hash).
 */
function tokensStored(?User $user = null): int
{
    $tokens = DB::table('personal_access_tokens');

    return $user === null ? $tokens->count() : $tokens->where('tokenable_id', $user->id)->count();
}

describe('making a token', function (): void {
    it('gives the value once, with the prefix, and a token that works with it', function (): void {
        $this->freezeTime();
        $user = inventoryPerson('Ada');

        $response = inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Stock script', 'abilities' => ['read', 'write']])->assertCreated();
        $secret = $response->json('data.plain_text_token');
        $stored = DB::table('personal_access_tokens')->sole();

        expect($response->json('data'))->toBe([
            'id' => $stored->id,
            'name' => 'Stock script',
            'abilities' => ['read', 'write'],
            'created_at' => now()->toIso8601String(),
            'last_used_at' => null,
            'expires_at' => now()->addDays(90)->toIso8601String(),
            'plain_text_token' => $secret,
        ])
            ->and($secret)->toStartWith('inv_')->and(strlen($secret))->toBeGreaterThan(40)->and($secret)->not->toContain('|')
            // What is kept is a hash: the value cannot be read back from the database.
            ->and($stored->token)->toBe(hash('sha256', $secret))->and(json_encode($stored))->not->toContain($secret)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        tokenCall($this, $secret, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        // Sanctum's own form of a token, with the id in front, is read as well.
        tokenCall($this, $stored->id.'|'.$secret, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    });

    it('never gives the value again: the list holds the metadata only', function (): void {
        $user = inventoryPerson('Ada');
        $secret = inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Stock script', 'abilities' => ['read']])->json('data.plain_text_token');

        $list = inventoryCall($this, $user, 'GET', '/api/v1/me/tokens')->assertOk();

        expect(array_keys($list->json('data.0')))->toBe(['id', 'name', 'abilities', 'created_at', 'last_used_at', 'expires_at'])
            ->and($list->getContent())->not->toContain($secret)->not->toContain(hash('sha256', $secret))->not->toContain('plain_text_token');
    });

    it('stores the abilities once each, in the order of the abilities, whatever the order they were asked in', function (): void {
        $user = inventoryPerson('Ada');

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'All', 'abilities' => ['manage', 'read', 'write']])
            ->assertCreated()->assertJsonPath('data.abilities', ['read', 'write', 'manage']);

        expect(json_decode((string) DB::table('personal_access_tokens')->value('abilities'), true))->toBe(['read', 'write', 'manage']);
    });

    it('trims the name', function (): void {
        $user = inventoryPerson('Ada');

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => "  Stock script \u{00A0}", 'abilities' => ['read']])
            ->assertCreated()->assertJsonPath('data.name', 'Stock script');
    });

    it('accepts a name of 80 characters', function (): void {
        $user = inventoryPerson('Ada');

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => str_repeat('é', 80), 'abilities' => ['read']])->assertCreated();
    });

    it('makes a token that lives for the number of days asked for, or for ever, or 90 days when nothing is said', function (): void {
        $this->freezeTime();
        $user = inventoryPerson('Ada');

        foreach ([7, 30, 90, 365] as $days) {
            inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => "{$days} days", 'abilities' => ['read'], 'expires_in_days' => $days])
                ->assertCreated()->assertJsonPath('data.expires_at', now()->addDays($days)->toIso8601String());
        }

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'For ever', 'abilities' => ['read'], 'expires_in_days' => null])
            ->assertCreated()->assertJsonPath('data.expires_at', null);
        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Usual', 'abilities' => ['read']])
            ->assertCreated()->assertJsonPath('data.expires_at', now()->addDays(90)->toIso8601String());
    });

    it('does not make a token that never expires out of an empty lifetime: only a JSON null says "for ever"', function (mixed $empty): void {
        $user = inventoryPerson('Ada');

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Script', 'abilities' => ['read'], 'expires_in_days' => $empty])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_in_days']);

        expect(tokensStored())->toBe(0);
    })->with(['an empty text' => [''], 'spaces' => ['   ']]);

    it('does not make a token that never expires from a form either, which has no null to say it with', function (): void {
        $user = inventoryPerson('Ada');
        $this->actingAs($user);

        $this->post('/api/v1/me/tokens', ['name' => 'Script', 'abilities' => ['read'], 'expires_in_days' => ''], frontend(['Accept' => 'application/json']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_in_days']);

        expect(tokensStored())->toBe(0);

        // Left out of the form, the usual 90 days apply.
        $this->post('/api/v1/me/tokens', ['name' => 'Script', 'abilities' => ['read']], frontend(['Accept' => 'application/json']))->assertCreated();
    });

    it('refuses what is not a token', function (array $data, string $field): void {
        $user = inventoryPerson('Ada');

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', $data)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        expect(tokensStored())->toBe(0);
    })->with([
        'no name' => [['abilities' => ['read']], 'name'],
        'an empty name' => [['name' => '', 'abilities' => ['read']], 'name'],
        'a name of spaces' => [['name' => "  \u{00A0} ", 'abilities' => ['read']], 'name'],
        'a name of 81 characters' => [['name' => str_repeat('a', 81), 'abilities' => ['read']], 'name'],
        'a name that is a list' => [['name' => ['a'], 'abilities' => ['read']], 'name'],
        'no abilities' => [['name' => 'Script'], 'abilities'],
        'an empty list of abilities' => [['name' => 'Script', 'abilities' => []], 'abilities'],
        'abilities that are not a list' => [['name' => 'Script', 'abilities' => 'read'], 'abilities'],
        'an ability that does not exist' => [['name' => 'Script', 'abilities' => ['read', 'delete']], 'abilities.1'],
        'every ability' => [['name' => 'Script', 'abilities' => ['*']], 'abilities.0'],
        'the same ability twice' => [['name' => 'Script', 'abilities' => ['read', 'read']], 'abilities.0'],
        'four abilities' => [['name' => 'Script', 'abilities' => ['read', 'write', 'manage', 'read']], 'abilities'],
        'a lifetime that is not offered' => [['name' => 'Script', 'abilities' => ['read'], 'expires_in_days' => 14], 'expires_in_days'],
        'a lifetime of zero' => [['name' => 'Script', 'abilities' => ['read'], 'expires_in_days' => 0], 'expires_in_days'],
        'a lifetime that is a word' => [['name' => 'Script', 'abilities' => ['read'], 'expires_in_days' => 'forever'], 'expires_in_days'],
        'a lifetime with decimals' => [['name' => 'Script', 'abilities' => ['read'], 'expires_in_days' => 30.5], 'expires_in_days'],
    ]);

    it('is for signed-in people', function (): void {
        $this->postJson('/api/v1/me/tokens', ['name' => 'Script', 'abilities' => ['read']], frontend())->assertUnauthorized();

        expect(tokensStored())->toBe(0);
    });

    it('has a pace: ten tokens an hour for a person, and the others have their own', function (): void {
        config(['inventories.api_token_creations_per_hour' => 2]);
        $ada = inventoryPerson('Ada');
        $grace = inventoryPerson('Grace');

        foreach ([1, 2] as $number) {
            inventoryCall($this, $ada, 'POST', '/api/v1/me/tokens', ['name' => "Script {$number}", 'abilities' => ['read']])->assertCreated();
        }

        $refused = inventoryCall($this, $ada, 'POST', '/api/v1/me/tokens', ['name' => 'Script 3', 'abilities' => ['read']])->assertTooManyRequests();
        inventoryCall($this, $grace, 'POST', '/api/v1/me/tokens', ['name' => 'Script', 'abilities' => ['read']])->assertCreated();

        expect((int) $refused->headers->get('Retry-After'))->toBeBetween(1, 3600)->and(tokensStored($ada))->toBe(2);

        $this->travel(61)->minutes();
        inventoryCall($this, $ada, 'POST', '/api/v1/me/tokens', ['name' => 'Script 3', 'abilities' => ['read']])->assertCreated();
    });
});

describe('the number of tokens', function (): void {
    it('is limited: past it the answer is a 409 with the limit, and nothing is made', function (): void {
        config(['inventories.max_api_tokens' => 2]);
        $user = inventoryPerson('Ada');

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'One', 'abilities' => ['read']])->assertCreated();
        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Two', 'abilities' => ['read']])->assertCreated();
        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Three', 'abilities' => ['read']], ['Accept-Language' => 'en'])
            ->assertConflict()
            ->assertExactJson([
                'message' => 'You can have 2 API tokens at a time: revoke one, or wait for one to expire.',
                'code' => 'token_limit_reached',
                'limit' => 2,
            ]);

        expect(tokensStored($user))->toBe(2);
    });

    it('does not count the tokens that expired, nor those of other people', function (): void {
        config(['inventories.max_api_tokens' => 2]);
        $user = inventoryPerson('Ada');
        $user->createToken('expired', ['read'], now()->subMinute());
        $user->createToken('live', ['read'], now()->addDay());
        inventoryPerson('Grace')->createToken('hers', ['read']);

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Fresh', 'abilities' => ['read']])->assertCreated();
        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Too many', 'abilities' => ['read']])->assertConflict();
    });

    it('counts a token that never expires', function (): void {
        config(['inventories.max_api_tokens' => 1]);
        $user = inventoryPerson('Ada');
        $user->createToken('forever', ['read']);

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Another', 'abilities' => ['read']])->assertConflict()->assertJsonPath('code', 'token_limit_reached');
    });

    it('gives the place back when a token is revoked, and when one expires', function (): void {
        config(['inventories.max_api_tokens' => 1]);
        $user = inventoryPerson('Ada');
        $first = inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'One', 'abilities' => ['read'], 'expires_in_days' => 7])->assertCreated();

        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Two', 'abilities' => ['read']])->assertConflict();

        inventoryCall($this, $user, 'DELETE', '/api/v1/me/tokens/'.$first->json('data.id'))->assertNoContent();
        $second = inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Two', 'abilities' => ['read'], 'expires_in_days' => 7])->assertCreated();

        $this->travel(8)->days();
        inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Three', 'abilities' => ['read']])->assertCreated();

        expect($second->json('data.name'))->toBe('Two');
    });
});

describe('the list of tokens', function (): void {
    it('has the tokens of the person that did not expire, the newest first', function (): void {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 0));
        $user = inventoryPerson('Ada');
        $oldest = $user->createToken('oldest', ['read']);
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(8, 0));
        $user->createToken('expires soon', ['write'], now()->addDays(3));
        $expired = $user->createToken('expired', ['read'], now()->subDay());
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(8, 0));
        $newest = $user->createToken('newest', ['*'], now()->addDays(30));
        inventoryPerson('Grace')->createToken('hers', ['read']);
        $oldest->accessToken->forceFill(['last_used_at' => now()->subHour()])->save();

        $list = inventoryCall($this, $user, 'GET', '/api/v1/me/tokens')->assertOk();

        expect(array_column($list->json('data'), 'name'))->toBe(['newest', 'expires soon', 'oldest'])
            ->and($list->json('data.0'))->toBe([
                'id' => $newest->accessToken->id,
                'name' => 'newest',
                // A token made with "*" can do all of it.
                'abilities' => ['read', 'write', 'manage'],
                'created_at' => '2026-10-03T08:00:00+00:00',
                'last_used_at' => null,
                'expires_at' => '2026-11-02T08:00:00+00:00',
            ])
            ->and($list->json('data.2.last_used_at'))->toBe('2026-10-03T07:00:00+00:00')
            ->and($list->json('data.2.expires_at'))->toBeNull()
            ->and(array_column($list->json('data'), 'id'))->not->toContain($expired->accessToken->id);
    });

    it('is empty for a person without tokens', function (): void {
        inventoryCall($this, inventoryPerson('Ada'), 'GET', '/api/v1/me/tokens')->assertOk()->assertExactJson(['data' => []]);
    });

    it('is for signed-in people', function (): void {
        $this->getJson('/api/v1/me/tokens', frontend())->assertUnauthorized();
    });
});

describe('revoking a token', function (): void {
    it('stops it at once', function (): void {
        $user = inventoryPerson('Ada');
        $made = inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Script', 'abilities' => ['read']]);
        $secret = $made->json('data.plain_text_token');

        tokenCall($this, $secret, 'GET', '/api/v1/me')->assertOk();

        inventoryCall($this, $user, 'DELETE', '/api/v1/me/tokens/'.$made->json('data.id'))->assertNoContent();

        tokenCall($this, $secret, 'GET', '/api/v1/me')->assertUnauthorized();
        expect(tokensStored())->toBe(0);
    });

    it('leaves the other tokens of the person alone', function (): void {
        $user = inventoryPerson('Ada');
        $keep = $user->createToken('keep', ['read']);
        $revoke = $user->createToken('revoke', ['read']);

        inventoryCall($this, $user, 'DELETE', '/api/v1/me/tokens/'.$revoke->accessToken->id)->assertNoContent();

        tokenCall($this, $keep->plainTextToken, 'GET', '/api/v1/me')->assertOk();
    });

    it('is a 404 for the token of somebody else, which stays', function (): void {
        $ada = inventoryPerson('Ada');
        $hers = inventoryPerson('Grace')->createToken('hers', ['read']);

        inventoryCall($this, $ada, 'DELETE', '/api/v1/me/tokens/'.$hers->accessToken->id)->assertNotFound();

        tokenCall($this, $hers->plainTextToken, 'GET', '/api/v1/me')->assertOk();
        expect(tokensStored())->toBe(1);
    });

    it('is a 404 for a token that does not exist, however its id is written', function (string $id): void {
        inventoryCall($this, inventoryPerson('Ada'), 'DELETE', '/api/v1/me/tokens/'.$id)->assertNotFound();
    })->with(['1', '999999', '99999999999999999999', 'abc', '-1', '1.5']);

    it('is a 404 the second time', function (): void {
        $user = inventoryPerson('Ada');
        $token = $user->createToken('script', ['read']);

        inventoryCall($this, $user, 'DELETE', '/api/v1/me/tokens/'.$token->accessToken->id)->assertNoContent();
        inventoryCall($this, $user, 'DELETE', '/api/v1/me/tokens/'.$token->accessToken->id)->assertNotFound();
    });

    it('can revoke a token that has expired, which is no longer listed', function (): void {
        $user = inventoryPerson('Ada');
        $token = $user->createToken('script', ['read'], now()->subMinute());

        inventoryCall($this, $user, 'DELETE', '/api/v1/me/tokens/'.$token->accessToken->id)->assertNoContent();
    });

    it('is for signed-in people', function (): void {
        $token = inventoryPerson('Ada')->createToken('script', ['read']);

        $this->deleteJson('/api/v1/me/tokens/'.$token->accessToken->id, [], frontend())->assertUnauthorized();

        expect(tokensStored())->toBe(1);
    });
});

describe('a token', function (): void {
    it('can neither list, make nor revoke tokens, whatever it may do and whatever it says it is', function (bool $saysItIsTheFront): void {
        $user = inventoryPerson('Ada');
        $token = $user->createToken('script', ['*']);
        $headers = $saysItIsTheFront ? frontend() : [];

        tokenCall($this, $token->plainTextToken, 'GET', '/api/v1/me/tokens', [], $headers)->assertForbidden()->assertJsonPath('code', 'session_required');
        tokenCall($this, $token->plainTextToken, 'POST', '/api/v1/me/tokens', ['name' => 'Copy', 'abilities' => ['read']], $headers)
            ->assertForbidden()->assertJsonPath('code', 'session_required');
        tokenCall($this, $token->plainTextToken, 'DELETE', '/api/v1/me/tokens/'.$token->accessToken->id, [], $headers)
            ->assertForbidden()->assertJsonPath('code', 'session_required');

        expect(tokensStored($user))->toBe(1);
    })->with(['from anywhere' => [false], 'with the Origin of the front' => [true]]);
});

describe('the tokens of an account', function (): void {
    it('go with the account, and are revoked by signing out everywhere', function (): void {
        $user = inventoryPerson('Ada');
        $made = inventoryCall($this, $user, 'POST', '/api/v1/me/tokens', ['name' => 'Script', 'abilities' => ['read']]);

        inventoryCall($this, $user, 'POST', '/api/v1/me/sign-out-everywhere')->assertNoContent();

        tokenCall($this, $made->json('data.plain_text_token'), 'GET', '/api/v1/me')->assertUnauthorized();
        expect(tokensStored($user))->toBe(0);
    });
});
