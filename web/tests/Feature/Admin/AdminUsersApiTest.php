<?php

declare(strict_types=1);

use App\Models\LoginCode;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * An administrator with the personal workspace signing up gives.
 */
function adminAccount(string $email = 'admin@example.com'): User
{
    return User::factory()->admin()->withPersonalWorkspace()->create(['email' => $email, 'name' => 'Admin Istrator']);
}

/**
 * Calls the administration API the way the web front does.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function adminCall(object $test, ?User $as, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    $test = $as === null ? $test : $test->actingAs($as);

    return $test->json($method, $uri, $data, frontend($headers));
}

/**
 * Adds a member to a workspace, joining after everybody who is already in it.
 */
function adminJoin(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceMember
{
    $workspace->members()->attach($user->id, ['role' => $role->value]);

    return WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->firstOrFail();
}

/**
 * The role a user holds in a workspace, as stored.
 */
function adminRoleOf(User $user, Workspace $workspace): ?string
{
    $role = DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->value('role');

    return is_string($role) ? $role : null;
}

/**
 * Runs the callback and returns the lines the administration wrote in the log meanwhile: message and context.
 *
 * @return list<array{string, array<string, mixed>}>
 */
function administrationLog(Closure $callback): array
{
    $lines = [];

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$lines): void {
        if ($logged->level === 'info' && str_starts_with((string) $logged->message, 'Administration:')) {
            $lines[] = [(string) $logged->message, $logged->context];
        }
    });

    $callback();

    return $lines;
}

dataset('administration calls', [
    'list' => ['GET', '/api/v1/admin/users', []],
    'create' => ['POST', '/api/v1/admin/users', ['email' => 'new@example.com', 'name' => 'New Person']],
    'promote' => ['PATCH', '/api/v1/admin/users/{id}', ['is_admin' => true]],
    'delete' => ['DELETE', '/api/v1/admin/users/{id}', []],
]);

describe('who may call it', function (): void {
    it('refuses a visitor', function (string $method, string $uri, array $data): void {
        $victim = User::factory()->create();

        adminCall($this, null, $method, str_replace('{id}', (string) $victim->id, $uri), $data)->assertUnauthorized();

        expect(User::query()->count())->toBe(1)->and($victim->fresh()?->is_admin)->toBeFalse();
    })->with('administration calls');

    it('refuses an account that does not administer the instance, and changes nothing', function (string $method, string $uri, array $data): void {
        $member = User::factory()->withPersonalWorkspace()->create();
        $victim = User::factory()->withPersonalWorkspace()->create();

        adminCall($this, $member, $method, str_replace('{id}', (string) $victim->id, $uri), $data)->assertForbidden();

        expect(User::query()->count())->toBe(2)
            ->and($victim->fresh()?->is_admin)->toBeFalse()
            ->and($member->fresh()?->is_admin)->toBeFalse()
            ->and(sentMails())->toBe([]);
    })->with('administration calls');

    it('is for the web front only: an administrator calling from elsewhere, or with a token, is refused with a reason', function (): void {
        $admin = adminAccount();
        $victim = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertForbidden()->assertJsonPath('code', 'not_stateful');
        $this->flushSession();
        // A token is told it is not allowed, not that the address is not stateful: the administrator could do nothing about it.
        $this->withToken($admin->createToken('script')->plainTextToken)->deleteJson('/api/v1/admin/users/'.$victim->id)
            ->assertForbidden()->assertJsonPath('code', 'session_required');

        expect(User::query()->count())->toBe(2);
    });

    it('refuses an API token even when the call looks like the front: the Origin of the application is not a secret', function (string $method, string $uri, array $data): void {
        $admin = adminAccount();
        $victim = User::factory()->create();
        $token = $admin->createToken('script', ['*'])->plainTextToken;

        // The session has nobody in it, so Sanctum falls back to the token: a token must not do what a person does.
        $this->withToken($token)->json($method, str_replace('{id}', (string) $victim->id, $uri), $data, frontend())
            ->assertForbidden()
            ->assertJsonPath('code', 'session_required');

        expect(User::query()->count())->toBe(2)->and($victim->fresh()?->is_admin)->toBeFalse()->and(sentMails())->toBe([]);
    })->with('administration calls');

    it('still answers an unknown token with 401, as every other endpoint does', function (): void {
        $this->withToken('1|not-a-real-token')->getJson('/api/v1/admin/users', frontend())->assertUnauthorized();
    });

    it('tells a non-administrator the same about an account that exists and one that does not: the ids are not for counting', function (string $method, array $data): void {
        $member = User::factory()->withPersonalWorkspace()->create();
        $existing = User::factory()->create();

        $known = adminCall($this, $member, $method, "/api/v1/admin/users/{$existing->id}", $data);
        $this->flushSession();
        $unknown = adminCall($this, $member, $method, '/api/v1/admin/users/424242', $data);

        expect($known->getStatusCode())->toBe(403)
            ->and($unknown->getStatusCode())->toBe(403)
            ->and($unknown->getContent())->toBe($known->getContent());
    })->with([
        'promoting' => ['PATCH', ['is_admin' => true]],
        'deleting' => ['DELETE', []],
    ]);

    it('judges the administrator on the account as stored: one demoted a moment ago is refused', function (): void {
        $admin = adminAccount();
        $other = adminAccount('other@example.com');

        adminCall($this, $admin, 'GET', '/api/v1/admin/users')->assertOk();

        User::query()->whereKey($admin->id)->update(['is_admin' => false]);
        $this->flushSession();

        adminCall($this, $admin->fresh(), 'GET', '/api/v1/admin/users')->assertForbidden();
        expect($other->fresh()?->is_admin)->toBeTrue();
    });

    it('answers 404 to an id that no account can have, without asking the database', function (string $id): void {
        $admin = adminAccount();

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$id}", ['is_admin' => true])->assertNotFound();
        $this->flushSession();
        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$id}")->assertNotFound();
    })->with(['letters' => ['abc'], 'a decimal' => ['1.5'], 'too many digits' => ['99999999999999999999999'], 'unknown' => ['424242']]);
});

describe('listing the accounts', function (): void {
    it('lists them newest first with what an administrator needs to know', function (): void {
        $admin = adminAccount();
        $ada = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'locale' => 'en']);
        app(Workspaces::class)->create($ada, 'Shop');

        $answer = adminCall($this, $admin, 'GET', '/api/v1/admin/users')->assertOk();

        $answer->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $ada->id)
            ->assertJsonPath('data.0.name', 'Ada Lovelace')
            ->assertJsonPath('data.0.email', 'ada@example.com')
            ->assertJsonPath('data.0.locale', 'en')
            ->assertJsonPath('data.0.is_admin', false)
            ->assertJsonPath('data.0.is_self', false)
            ->assertJsonPath('data.0.workspaces_count', 2)
            ->assertJsonPath('data.1.id', $admin->id)
            ->assertJsonPath('data.1.is_admin', true)
            ->assertJsonPath('data.1.is_self', true)
            ->assertJsonPath('data.1.workspaces_count', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);

        expect($answer->json('data.0.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T/')
            ->and($answer->json('data.0'))->not->toHaveKeys(['remember_token', 'password', 'current_workspace_id']);
    });

    it('pages by 25, and keeps the search from one page to the next', function (): void {
        $admin = adminAccount();
        User::factory()->count(29)->sequence(fn ($sequence) => ['name' => 'Member '.$sequence->index, 'email' => "member{$sequence->index}@example.com"])->create();
        User::factory()->count(3)->sequence(fn ($sequence) => ['name' => 'Other '.$sequence->index, 'email' => "other{$sequence->index}@example.org"])->create();

        $first = adminCall($this, $admin, 'GET', '/api/v1/admin/users')->assertOk();
        $second = adminCall($this, $admin, 'GET', '/api/v1/admin/users?page=2')->assertOk();
        $searched = adminCall($this, $admin, 'GET', '/api/v1/admin/users?q=member&page=2')->assertOk();

        expect($first->json('data'))->toHaveCount(25)
            ->and($first->json('meta'))->toMatchArray(['total' => 33, 'last_page' => 2, 'per_page' => 25, 'from' => 1, 'to' => 25])
            ->and($second->json('data'))->toHaveCount(8)
            ->and($second->json('meta'))->toMatchArray(['current_page' => 2, 'from' => 26, 'to' => 33])
            ->and($searched->json('data'))->toHaveCount(4)
            ->and($searched->json('meta.total'))->toBe(29)
            // Nobody twice, nobody missing.
            ->and(array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')))->toHaveCount(33);
        expect(array_unique(array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'))))->toHaveCount(33);
    });

    it('answers an empty page past the end, with the real last page', function (): void {
        $admin = adminAccount();

        adminCall($this, $admin, 'GET', '/api/v1/admin/users?page=7')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 1);
    });

    it('searches names and addresses, ignoring case, on every database', function (string $term, array $expected): void {
        $admin = adminAccount('boss@corp.test');
        $admin->forceFill(['name' => 'The Boss'])->save();
        User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace.hopper@navy.test']);
        User::factory()->create(['name' => 'élodie Martin', 'email' => 'elodie@example.com']);
        User::factory()->create(['name' => '100% Real_Name', 'email' => 'real@example.com']);

        $names = collect(adminCall($this, $admin, 'GET', '/api/v1/admin/users', ['q' => $term])->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($names)->toBe($expected);
    })->with([
        'a name, in capitals' => ['ADA', ['Ada Lovelace']],
        'a name, in lowercase' => ['hopper', ['Grace Hopper']],
        'a part of an address' => ['navy.test', ['Grace Hopper']],
        'an address in capitals' => ['GRACE.HOPPER@NAVY.TEST', ['Grace Hopper']],
        'name or address' => ['example.com', ['100% Real_Name', 'Ada Lovelace', 'élodie Martin']],
        'surrounded by spaces' => ['  lovelace ', ['Ada Lovelace']],
        'an accented letter' => ['ÉLODIE', ['élodie Martin']],
        'a percent sign is a character' => ['100%', ['100% Real_Name']],
        'an underscore is a character' => ['e_l', []],
        'an underscore that is there' => ['l_na', ['100% Real_Name']],
        'a lone percent sign' => ['%', ['100% Real_Name']],
        'nothing' => ['zzz', []],
        'everything when empty' => ['', ['100% Real_Name', 'Ada Lovelace', 'Grace Hopper', 'The Boss', 'élodie Martin']],
    ]);

    it('refuses a search or a page that makes no sense', function (array $query): void {
        $admin = adminAccount();

        adminCall($this, $admin, 'GET', '/api/v1/admin/users?'.http_build_query($query))->assertUnprocessable();
    })->with([
        'a search of a thousand characters' => [['q' => str_repeat('a', 1000)]],
        'a list as search' => [['q' => ['a']]],
        'page zero' => [['page' => 0]],
        'a word as page' => [['page' => 'last']],
        'a huge page' => [['page' => 99999999999]],
    ]);
});

describe('creating an account', function (): void {
    it('is refused to an account that is not an administrator any more, whatever the address', function (): void {
        // An administrator who has just been demoted elsewhere, but whose session still says otherwise: a race the
        // gate cannot see, which the check against the stored account must.
        $actor = User::factory()->withPersonalWorkspace()->create();
        $actor->is_admin = true;
        User::factory()->create(['email' => 'taken@example.com']);

        adminCall($this, $actor, 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_administrator');

        // The address is not given away either: an account that already exists is refused the same way.
        adminCall($this, $actor, 'POST', '/api/v1/admin/users', ['email' => 'taken@example.com', 'name' => 'Ada Lovelace'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_administrator');

        expect(User::query()->where('email', 'ada@example.com')->exists())->toBeFalse()
            ->and(User::query()->count())->toBe(2);
    });

    it('creates it with its personal workspace, as signing up would', function (): void {
        $admin = adminAccount();

        $answer = adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.is_self', false)
            ->assertJsonPath('data.workspaces_count', 1);

        $user = User::query()->where('email', 'ada@example.com')->sole();

        expect($answer->json('data.id'))->toBe($user->id)
            ->and($user->is_admin)->toBeFalse()
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->getRememberToken())->not->toBeEmpty()
            ->and($user->workspaces)->toHaveCount(1)
            ->and($user->workspaces->first()?->pivot->role)->toBe(WorkspaceRole::Owner)
            ->and($user->current_workspace_id)->toBe($user->workspaces->first()?->id)
            // The instance's language, not the one the administrator happens to read (the tests' default is French).
            ->and($user->locale)->toBe('fr')
            ->and($user->workspaces->first()?->name)->toBe('Espace personnel')
            // The account is not signed in by this, and no code was created.
            ->and(LoginCode::query()->count())->toBe(0);
    });

    it('stores the address in canonical form and the name trimmed', function (): void {
        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => "  Ada.Lovelace@Example.COM \n", 'name' => "\u{A0} Ada Lovelace \u{200B}"])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ada.lovelace@example.com')
            ->assertJsonPath('data.name', 'Ada Lovelace');

        expect(User::query()->where('email', 'ada.lovelace@example.com')->count())->toBe(1);
    });

    it('answers 409 when the address already has an account, whatever its case, and creates nothing', function (string $email): void {
        $admin = adminAccount();
        User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada']);

        adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => $email, 'name' => 'Somebody else'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_exists');

        expect(User::query()->count())->toBe(2)
            ->and(User::query()->where('email', 'ada@example.com')->value('name'))->toBe('Ada')
            ->and(sentMails())->toBe([]);
    })->with(['the same' => ['ada@example.com'], 'in capitals' => [' ADA@Example.com ']]);

    it('answers 409 to the loser of a race for the same address, who is handed the winner\'s account', function (): void {
        $admin = adminAccount();
        $raced = false;

        // Somebody else creates the account between the check for it and the insert.
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if (! $raced && str_starts_with($query->sql, 'select exists') && str_contains($query->sql, 'users')) {
                $raced = true;
                DB::table('users')->insert(['ulid' => User::newUlid(), 'name' => 'Winner', 'email' => 'ada@example.com', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_exists');

        expect($raced)->toBeTrue()
            ->and(User::query()->where('email', 'ada@example.com')->sole()->name)->toBe('Winner')
            ->and(sentMails())->toBe([]);
    });

    it('refuses a name with a control character in it, line breaks included, and creates nothing', function (string $name): void {
        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => $name])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        expect(User::query()->count())->toBe(1)->and(sentMails())->toBe([]);
    })->with('names with a control character');

    it('accepts unusual names', function (string $name): void {
        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => $name])
            ->assertCreated()
            ->assertJsonPath('data.name', $name);
    })->with('unusual names that are fine');

    it('refuses an address or a name that is not valid', function (array $data, string $field): void {
        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', $data)->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(User::query()->count())->toBe(1)->and(sentMails())->toBe([]);
    })->with([
        'no address' => [['name' => 'Ada'], 'email'],
        'not an address' => [['email' => 'ada', 'name' => 'Ada'], 'email'],
        'an accent before the @' => [['email' => 'adé@example.com', 'name' => 'Ada'], 'email'],
        'a list as address' => [['email' => ['a@example.com'], 'name' => 'Ada'], 'email'],
        'no name' => [['email' => 'ada@example.com'], 'name'],
        'a name of spaces' => [['email' => 'ada@example.com', 'name' => " \u{A0}\u{200B} "], 'name'],
        'a name too long' => [['email' => 'ada@example.com', 'name' => str_repeat('a', 256)], 'name'],
        'a list as name' => [['email' => 'ada@example.com', 'name' => ['Ada']], 'name'],
    ]);
});

describe('promoting and demoting', function (): void {
    it('makes an account an administrator, at once', function (): void {
        $admin = adminAccount();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada->id}", ['is_admin' => true])
            ->assertOk()
            ->assertJsonPath('data.id', $ada->id)
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonPath('data.workspaces_count', 1);

        $this->flushSession();

        expect($ada->fresh()?->is_admin)->toBeTrue();
        adminCall($this, $ada->fresh(), 'GET', '/api/v1/admin/users')->assertOk();
    });

    it('takes administrator rights away, at once, when another administrator remains', function (): void {
        $admin = adminAccount();
        $other = adminAccount('other@example.com');

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$other->id}", ['is_admin' => false])
            ->assertOk()
            ->assertJsonPath('data.is_admin', false);

        $this->flushSession();

        expect($other->fresh()?->is_admin)->toBeFalse();
        adminCall($this, $other->fresh(), 'GET', '/api/v1/admin/users')->assertForbidden();
    });

    it('lets an administrator step down when another one remains', function (): void {
        $admin = adminAccount();
        adminAccount('other@example.com');

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$admin->id}", ['is_admin' => false])->assertOk()->assertJsonPath('data.is_self', true);

        expect($admin->fresh()?->is_admin)->toBeFalse();
    });

    it('refuses to demote the last administrator', function (): void {
        $admin = adminAccount();
        User::factory()->create();

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$admin->id}", ['is_admin' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_administrator');

        expect($admin->fresh()?->is_admin)->toBeTrue();
    });

    it('refuses a change asked by an administrator who was demoted while the request was handled', function (): void {
        $admin = adminAccount();
        adminAccount('other@example.com');
        $ada = User::factory()->create();
        $demoted = false;

        // The gate lets the administrator in; another administrator demotes them before the locks are taken.
        DB::listen(function (QueryExecuted $query) use (&$demoted, $admin): void {
            if (! $demoted && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'users')) {
                $demoted = true;
                DB::table('users')->where('id', $admin->id)->update(['is_admin' => false]);
            }
        });

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada->id}", ['is_admin' => true])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_administrator');

        expect($demoted)->toBeTrue()->and($ada->fresh()?->is_admin)->toBeFalse();
    });

    it('changes nothing, and refuses nothing, when the account already is what is asked', function (): void {
        $admin = adminAccount();
        $ada = User::factory()->create();

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada->id}", ['is_admin' => false])->assertOk()->assertJsonPath('data.is_admin', false);
        $this->flushSession();
        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$admin->id}", ['is_admin' => true])->assertOk()->assertJsonPath('data.is_admin', true);
    });

    it('accepts the usual spellings of a boolean and refuses the rest', function (mixed $value, int $status): void {
        $admin = adminAccount();
        $ada = User::factory()->create();

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada->id}", ['is_admin' => $value])->assertStatus($status);
    })->with([
        'true' => [true, 200],
        'one' => [1, 200],
        'the string zero' => ['0', 200],
        'missing' => [null, 422],
        'a word' => ['yes', 422],
        'a list' => [[true], 422],
    ]);

    it('answers 404 for an account that is not there', function (): void {
        adminCall($this, adminAccount(), 'PATCH', '/api/v1/admin/users/424242', ['is_admin' => true])->assertNotFound()->assertJsonPath('code', 'account_not_found');
    });
});

describe('deleting an account', function (): void {
    it('deletes it the way its owner would, with the workspaces nobody else belongs to', function (): void {
        $admin = adminAccount();
        $ada = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $grace = User::factory()->withPersonalWorkspace()->create(['email' => 'grace@example.com']);
        $alone = app(Workspaces::class)->create($ada, 'Ada alone');
        $shared = app(Workspaces::class)->create($grace, 'Grace shares');
        adminJoin($shared, $ada, WorkspaceRole::Editor);
        $personal = $ada->workspaces()->orderBy('workspace_user.id')->firstOrFail();
        $ada->createToken('script');
        $code = receiveCode($this, 'ada@example.com');
        $this->flushSession();

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$ada->id}")->assertNoContent();

        expect(User::query()->whereKey($ada->id)->exists())->toBeFalse()
            ->and(Workspace::query()->whereKey($alone->id)->exists())->toBeFalse()
            ->and(Workspace::query()->whereKey($personal->id)->exists())->toBeFalse()
            // What is shared stays, and the others are untouched.
            ->and(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue()
            ->and(adminRoleOf($grace, $shared))->toBe('owner')
            ->and(WorkspaceMember::query()->where('workspace_id', $shared->id)->count())->toBe(1)
            ->and(User::query()->count())->toBe(2)
            // Its sign-in code and its tokens go with it: the code that was pending no longer opens anything.
            ->and(LoginCode::query()->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->count())->toBe(0)
            ->and($code)->toMatch('/^\d{6}$/');
    });

    it('refuses to delete the account of the administrator who asks, from here', function (): void {
        $admin = adminAccount();
        adminAccount('other@example.com');

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'self_deletion');

        expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
    });

    it('can delete another administrator', function (): void {
        $admin = adminAccount();
        $other = adminAccount('other@example.com');

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$other->id}")->assertNoContent();

        expect(User::query()->where('is_admin', true)->count())->toBe(1);
    });

    it('refuses a deletion asked by an account that is not an administrator any more, and then hands nothing over', function (): void {
        // An administrator who has just been demoted elsewhere, but whose session still says otherwise: a race the
        // gate cannot see, which the check under the locks must.
        $actor = User::factory()->withPersonalWorkspace()->create();
        $actor->is_admin = true;
        $last = adminAccount('last@example.com');
        $viewer = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($last, 'Shared');
        adminJoin($shared, $viewer, WorkspaceRole::Viewer);

        adminCall($this, $actor, 'DELETE', "/api/v1/admin/users/{$last->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'not_administrator');

        // The hand-over was part of the same transaction: it went away with the refusal.
        expect(User::query()->whereKey($last->id)->exists())->toBeTrue()
            ->and(adminRoleOf($viewer, $shared))->toBe('viewer')
            ->and(adminRoleOf($last, $shared))->toBe('owner');
    });

    it('refuses a deletion asked by an administrator who was demoted while the request was handled', function (): void {
        $admin = adminAccount();
        $other = adminAccount('other@example.com');
        $ada = User::factory()->withPersonalWorkspace()->create();
        $demoted = false;

        // The gate lets the administrator in; another administrator demotes them before the locks are taken.
        DB::listen(function (QueryExecuted $query) use (&$demoted, $admin): void {
            if (! $demoted && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'users')) {
                $demoted = true;
                DB::table('users')->where('id', $admin->id)->update(['is_admin' => false]);
            }
        });

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$ada->id}")->assertForbidden()->assertJsonPath('code', 'not_administrator');

        expect($demoted)->toBeTrue()
            ->and(User::query()->whereKey($ada->id)->exists())->toBeTrue()
            ->and(User::query()->whereKey($other->id)->value('is_admin'))->toBeTrue();
    });

    it('answers 404 for an account that is not there', function (): void {
        adminCall($this, adminAccount(), 'DELETE', '/api/v1/admin/users/424242')->assertNotFound()->assertJsonPath('code', 'account_not_found');
    });

    it('answers 404 when the account was deleted while the request was handled', function (): void {
        $admin = adminAccount();
        $ada = User::factory()->create();
        $gone = false;

        // The route finds the account; it is gone when the deletion looks at it.
        DB::listen(function (QueryExecuted $query) use (&$gone, $ada): void {
            if (! $gone && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'users')) {
                $gone = true;
                DB::table('users')->where('id', $ada->id)->delete();
            }
        });

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$ada->id}")->assertNotFound()->assertJsonPath('code', 'account_not_found');
    });
});

describe('handing workspaces over before deleting their only owner', function (): void {
    it('makes the editor of the longest standing the owner, before any viewer', function (): void {
        $admin = adminAccount();
        $owner = User::factory()->withPersonalWorkspace()->create(['email' => 'owner@example.com']);
        // Created in the opposite order of joining: the oldest account is not the oldest member.
        $laterEditor = User::factory()->withPersonalWorkspace()->create(['email' => 'later@example.com']);
        $earlyEditor = User::factory()->withPersonalWorkspace()->create(['email' => 'early-editor@example.com']);
        $viewer = User::factory()->withPersonalWorkspace()->create(['email' => 'viewer@example.com']);
        $shared = app(Workspaces::class)->create($owner, 'Shared');
        adminJoin($shared, $viewer, WorkspaceRole::Viewer);
        adminJoin($shared, $earlyEditor, WorkspaceRole::Editor);
        adminJoin($shared, $laterEditor, WorkspaceRole::Editor);

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$owner->id}")->assertNoContent();

        expect(User::query()->whereKey($owner->id)->exists())->toBeFalse()
            ->and(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue()
            ->and(adminRoleOf($earlyEditor, $shared))->toBe('owner')
            ->and(adminRoleOf($laterEditor, $shared))->toBe('editor')
            ->and(adminRoleOf($viewer, $shared))->toBe('viewer')
            ->and(WorkspaceMember::query()->where('workspace_id', $shared->id)->count())->toBe(3);
    });

    it('withdraws the consent to the photo entry of a workspace whose owner is replaced, and only of those', function (): void {
        $admin = adminAccount();
        $owner = User::factory()->withPersonalWorkspace()->create();
        $successor = User::factory()->withPersonalWorkspace()->create();
        $coOwner = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->withPersonalWorkspace()->create();
        $handed = app(Workspaces::class)->create($owner, 'Handed over');
        adminJoin($handed, $successor, WorkspaceRole::Editor);
        $co = app(Workspaces::class)->create($owner, 'Co-owned');
        adminJoin($co, $coOwner, WorkspaceRole::Owner);
        $theirs = app(Workspaces::class)->create($guest, 'Theirs');
        adminJoin($theirs, $owner, WorkspaceRole::Editor);

        foreach ([$handed, $co, $theirs] as $workspace) {
            $workspace->forceFill(['photo_entry_enabled' => true])->save();
        }

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$owner->id}")->assertNoContent();

        // The successor did not consent; the workspace that keeps its owner keeps what its owner said.
        expect(adminRoleOf($successor, $handed))->toBe('owner')
            ->and($handed->fresh()->photo_entry_enabled)->toBeFalse()
            ->and($co->fresh()->photo_entry_enabled)->toBeTrue()
            ->and($theirs->fresh()->photo_entry_enabled)->toBeTrue();
    });

    it('falls back to the member of the longest standing when the workspace has no editor', function (): void {
        $admin = adminAccount();
        $owner = User::factory()->withPersonalWorkspace()->create();
        $late = User::factory()->withPersonalWorkspace()->create();
        $early = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($owner, 'Shared');
        adminJoin($shared, $early, WorkspaceRole::Viewer);
        adminJoin($shared, $late, WorkspaceRole::Viewer);

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$owner->id}")->assertNoContent();

        expect(adminRoleOf($early, $shared))->toBe('owner')
            ->and(adminRoleOf($late, $shared))->toBe('viewer');
    });

    it('hands over each shared workspace to its own successor', function (): void {
        $admin = adminAccount();
        $owner = User::factory()->withPersonalWorkspace()->create();
        $ada = User::factory()->withPersonalWorkspace()->create();
        $grace = User::factory()->withPersonalWorkspace()->create();
        $one = app(Workspaces::class)->create($owner, 'One');
        $two = app(Workspaces::class)->create($owner, 'Two');
        adminJoin($one, $grace, WorkspaceRole::Viewer);
        adminJoin($one, $ada, WorkspaceRole::Editor);
        adminJoin($two, $ada, WorkspaceRole::Viewer);
        adminJoin($two, $grace, WorkspaceRole::Editor);

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$owner->id}")->assertNoContent();

        expect(adminRoleOf($ada, $one))->toBe('owner')
            ->and(adminRoleOf($grace, $one))->toBe('viewer')
            ->and(adminRoleOf($grace, $two))->toBe('owner')
            ->and(adminRoleOf($ada, $two))->toBe('viewer');
    });

    it('leaves the roles alone when another owner remains, or when the account is not an owner', function (): void {
        $admin = adminAccount();
        $owner = User::factory()->withPersonalWorkspace()->create();
        $coOwner = User::factory()->withPersonalWorkspace()->create();
        $viewer = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->withPersonalWorkspace()->create();
        $co = app(Workspaces::class)->create($owner, 'Co-owned');
        adminJoin($co, $viewer, WorkspaceRole::Viewer);
        adminJoin($co, $coOwner, WorkspaceRole::Owner);
        $theirs = app(Workspaces::class)->create($guest, 'Theirs');
        adminJoin($theirs, $owner, WorkspaceRole::Editor);
        adminJoin($theirs, $viewer, WorkspaceRole::Viewer);

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$owner->id}")->assertNoContent();

        expect(adminRoleOf($viewer, $co))->toBe('viewer')
            ->and(adminRoleOf($coOwner, $co))->toBe('owner')
            ->and(adminRoleOf($guest, $theirs))->toBe('owner')
            ->and(adminRoleOf($viewer, $theirs))->toBe('viewer')
            ->and(WorkspaceMember::query()->where('user_id', $owner->id)->count())->toBe(0);
    });

    it('deletes a workspace the account is alone in, without handing it to anybody', function (): void {
        $admin = adminAccount();
        $owner = User::factory()->withPersonalWorkspace()->create();
        $alone = app(Workspaces::class)->create($owner, 'Alone');

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$owner->id}")->assertNoContent();

        expect(Workspace::query()->whereKey($alone->id)->exists())->toBeFalse();
    });

    it('works for an account that owns nothing at all', function (): void {
        $admin = adminAccount();
        $ghost = User::factory()->create();

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$ghost->id}")->assertNoContent();

        expect(User::query()->whereKey($ghost->id)->exists())->toBeFalse();
    });

    it('does not hand anything over when the deletion fails, whatever the reason', function (): void {
        $admin = adminAccount();
        $owner = User::factory()->withPersonalWorkspace()->create();
        $viewer = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($owner, 'Shared');
        adminJoin($shared, $viewer, WorkspaceRole::Viewer);
        User::deleting(fn () => throw new RuntimeException('Forced failure.'));

        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$owner->id}")->assertServerError();

        expect(User::query()->whereKey($owner->id)->exists())->toBeTrue()
            ->and(adminRoleOf($viewer, $shared))->toBe('viewer')
            ->and(adminRoleOf($owner, $shared))->toBe('owner');
    });
});

describe('the limit on creating accounts', function (): void {
    it('is 60 an hour unless configured otherwise', function (): void {
        expect(config('admin.user_creation_per_hour'))->toBe(60);
    });

    it('throttles creating, per administrator, with the answer the other limits give', function (): void {
        config(['admin.user_creation_per_hour' => 2]);
        $admin = adminAccount();
        $other = adminAccount('other@example.com');

        foreach ([1, 2] as $number) {
            $this->flushSession();
            adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => "new{$number}@example.com", 'name' => 'New'])->assertCreated();
        }

        $this->flushSession();
        adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => 'toomany@example.com', 'name' => 'New'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message']);

        // The allowance is the administrator's own, reading and the other changes are not counted, and an hour renews it.
        $this->flushSession();
        adminCall($this, $other, 'POST', '/api/v1/admin/users', ['email' => 'hers@example.com', 'name' => 'New'])->assertCreated();
        $this->flushSession();
        adminCall($this, $admin, 'GET', '/api/v1/admin/users')->assertOk();
        $this->travel(61)->minutes();
        $this->flushSession();
        adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => 'later@example.com', 'name' => 'New'])->assertCreated();

        expect(User::query()->where('email', 'toomany@example.com')->exists())->toBeFalse()
            ->and(User::query()->count())->toBe(2 + 2 + 1 + 1);
    });

    it('does not count what administrators do to existing accounts', function (): void {
        config(['admin.user_creation_per_hour' => 1]);
        $admin = adminAccount();
        $ada = User::factory()->create();

        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada->id}", ['is_admin' => true])->assertOk();
        $this->flushSession();
        adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada->id}", ['is_admin' => false])->assertOk();
        $this->flushSession();
        adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$ada->id}")->assertNoContent();
    });
});

describe('the log of what administrators do', function (): void {
    it('has one line for each account created, promoted, demoted and deleted, with the two ids and nothing else', function (): void {
        $admin = adminAccount();
        $ada = null;

        $lines = administrationLog(function () use ($admin, &$ada): void {
            $created = adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => 'ada.lovelace@example.com', 'name' => 'Ada Lovelace'])->assertCreated();
            $ada = $created->json('data.id');
            $this->flushSession();
            adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada}", ['is_admin' => true])->assertOk();
            $this->flushSession();
            adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada}", ['is_admin' => false])->assertOk();
            $this->flushSession();
            adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$ada}")->assertNoContent();
        });

        expect($lines)->toBe([
            ['Administration: account created.', ['actor_id' => $admin->id, 'target_id' => $ada]],
            ['Administration: account made an administrator.', ['actor_id' => $admin->id, 'target_id' => $ada]],
            ['Administration: administrator rights taken away.', ['actor_id' => $admin->id, 'target_id' => $ada]],
            ['Administration: account deleted.', ['actor_id' => $admin->id, 'target_id' => $ada]],
        ])->and(json_encode($lines))->not->toContain('@')->not->toContain('Lovelace')->not->toContain('Istrator');
    });

    it('says nothing about a request that changes nothing or is refused', function (): void {
        $admin = adminAccount();
        $ada = User::factory()->create(['email' => 'ada@example.com']);

        $lines = administrationLog(function () use ($admin, $ada): void {
            // Already what is asked, an address that has an account, the administrator's own account, an invalid name.
            adminCall($this, $admin, 'PATCH', "/api/v1/admin/users/{$ada->id}", ['is_admin' => false])->assertOk();
            $this->flushSession();
            adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada'])->assertStatus(409);
            $this->flushSession();
            adminCall($this, $admin, 'DELETE', "/api/v1/admin/users/{$admin->id}")->assertStatus(409);
            $this->flushSession();
            adminCall($this, $admin, 'POST', '/api/v1/admin/users', ['email' => 'grace@example.com', 'name' => "Gr\nace"])->assertUnprocessable();
        });

        expect($lines)->toBe([]);
    });
});

describe('the welcome mail', function (): void {
    it('is sent to the new address, in the default language of the instance, with the sign-in page', function (): void {
        config(['app.name' => 'Stock']);
        $login = rtrim(config()->string('app.url'), '/').'/login';

        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'], ['Accept-Language' => 'en'])->assertCreated();

        $mails = sentMails();

        expect($mails)->toHaveCount(1)
            ->and($mails[0]->getTo()[0]->getAddress())->toBe('ada@example.com')
            ->and($mails[0]->getSubject())->toBe('Votre compte Stock est prêt')
            ->and($mails[0]->getTextBody())->toContain('Bonjour Ada Lovelace,')->toContain('Un administrateur a créé un compte pour vous sur Stock')
            ->toContain($login)
            ->and($mails[0]->getHtmlBody())->toContain('<html lang="fr">')->toContain('href="'.$login.'"');
    });

    it('is written in English when that is the language of the instance, whatever the administrator reads', function (): void {
        config(['app.default_locale' => 'en']);

        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada'], ['Accept-Language' => 'fr'])->assertCreated();

        expect(sentMails()[0]->getSubject())->toStartWith('Your ')->toEndWith(' account is ready')
            ->and(sentMails()[0]->getTextBody())->toContain('Hello Ada,')->toContain('An administrator created an account for you')
            ->and(User::query()->where('email', 'ada@example.com')->value('locale'))->toBe('en')
            ->and(Workspace::query()->where('name', 'Personal space')->exists())->toBeTrue();
    });

    it('takes the address of the sign-in page from APP_URL, not from the request', function (): void {
        $this->withServerVariables(['HTTP_HOST' => 'evil.example.net'])->actingAs(adminAccount())
            ->postJson('/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada'], frontend())->assertCreated();

        expect(sentMails()[0]->getTextBody())->toContain(rtrim(config()->string('app.url'), '/').'/login')->not->toContain('evil.example.net');
    });

    it('carries no code and no secret: it only says how to get one', function (): void {
        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada'])->assertCreated();

        $mail = sentMails()[0];

        // (A colour such as #667382 is not a code: only a run of digits on its own is.)
        expect($mail->getTextBody())->not->toMatch('/(?<![#\w])\d{6}(?!\w)/')
            ->and($mail->getHtmlBody())->not->toMatch('/(?<![#\w])\d{6}(?!\w)/')->not->toContain('<img')->not->toContain('<script')->not->toContain('<link')
            ->and(LoginCode::query()->count())->toBe(0);
    });

    it('escapes the name once in the HTML part and prints it as it is in the text part', function (): void {
        config(['app.locale' => 'en', 'app.name' => "L'Atelier & <Co>"]);

        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada <b>"Countess"</b> & Co'])->assertCreated();

        $mail = sentMails()[0];

        expect($mail->getHtmlBody())->toContain('Ada &lt;b&gt;&quot;Countess&quot;&lt;/b&gt; &amp; Co')->not->toContain('<b>')->not->toContain('&amp;amp;')
            ->and($mail->getTextBody())->toContain('Ada <b>"Countess"</b> & Co')->toContain("L'Atelier & <Co>")->not->toContain('&amp;')->not->toContain('&#039;')
            ->and($mail->getSubject())->toContain("L'Atelier & <Co>");
    });

    it('is sent after the answer, not through the queue: nothing readable is kept in the jobs table', function (): void {
        config(['queue.default' => 'database']);

        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada'])->assertCreated();

        expect(sentMails())->toHaveCount(1)->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('can fail without failing the creation: the account stays, the failure is logged', function (): void {
        config([
            'mail.default' => 'unreachable',
            'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1],
        ]);
        Log::spy();

        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'ada@example.com', 'name' => 'Ada'])->assertCreated();

        expect(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Could not send a welcome email'))->once();
    });

    it('is not sent when the account is not created', function (): void {
        adminCall($this, adminAccount(), 'POST', '/api/v1/admin/users', ['email' => 'not an address', 'name' => 'Ada'])->assertUnprocessable();

        expect(sentMails())->toBe([]);
    });
});
