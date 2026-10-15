<?php

declare(strict_types=1);

use App\Auth\Exceptions\AccountDeletionRefused;
use App\Models\AccountDeletionCode;
use App\Models\LoginCode;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\Device;

uses(RefreshDatabase::class);

/**
 * Asks for a deletion code for a user and returns it as read from the mail.
 */
function deletionCodeFor(object $test, User $user): string
{
    $test->actingAs($user)->postJson('/api/v1/me/deletion-code', [], frontend())->assertOk();

    return lastSentCode();
}

/**
 * Confirms the deletion of an account with a code, the way the web front does.
 */
function confirmDeletion(object $test, User $user, mixed $code): TestResponse
{
    return $test->actingAs($user)->deleteJson('/api/v1/me', ['code' => $code], frontend());
}

/**
 * Starts a new request from nobody's session: the test client keeps the one of the previous call.
 */
function forgetPreviousRequest(object $test): void
{
    $test->flushSession();
}

describe('deleting the account', function (): void {
    it('deletes the account and everything that belongs to it alone, and nothing else', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $other = User::factory()->withPersonalWorkspace()->create(['email' => 'grace@example.com']);
        $viewer = User::factory()->withPersonalWorkspace()->create(['email' => 'alan@example.com']);

        $alone = app(Workspaces::class)->create($user, 'Mine alone');
        $hers = app(Workspaces::class)->create($other, 'Hers');
        $hers->members()->attach($user->id, ['role' => 'viewer']);
        $together = app(Workspaces::class)->create($user, 'Together');
        $together->members()->attach($other->id, ['role' => 'owner']);
        $together->members()->attach($viewer->id, ['role' => 'viewer']);
        $personal = $user->workspaces()->orderBy('workspace_user.id')->firstOrFail();

        $user->createToken('script');
        $other->createToken('script');
        $pending = receiveCode($this, 'ada@example.com');
        receiveCode($this, 'grace@example.com');
        $code = deletionCodeFor($this, $user);
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, $code)->assertNoContent();

        expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
            // What was the account's alone is gone: its workspaces, with their memberships...
            ->and(Workspace::query()->whereIn('id', [$personal->id, $alone->id])->exists())->toBeFalse()
            ->and(WorkspaceMember::query()->where('user_id', $user->id)->exists())->toBeFalse()
            // ...its tokens, the sign-in code waiting for its address, the deletion code...
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->exists())->toBeFalse()
            ->and(LoginCode::query()->where('email', 'ada@example.com')->exists())->toBeFalse()
            ->and(AccountDeletionCode::query()->exists())->toBeFalse()
            // ...while what other people share is theirs: the account just left.
            ->and(Workspace::query()->whereIn('id', [$hers->id, $together->id])->count())->toBe(2)
            ->and(WorkspaceMember::query()->where('workspace_id', $hers->id)->pluck('role', 'user_id')->all())->toBe([$other->id => WorkspaceRole::Owner])
            ->and(WorkspaceMember::query()->where('workspace_id', $together->id)->count())->toBe(2)
            ->and(User::query()->whereIn('id', [$other->id, $viewer->id])->count())->toBe(2)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $other->id)->exists())->toBeTrue()
            ->and(LoginCode::query()->where('email', 'grace@example.com')->exists())->toBeTrue()
            ->and($pending)->toMatch('/^\d{6}$/');
    });

    it('deletes the sessions the account has in the database, and ends this one', function (): void {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $other = User::factory()->withPersonalWorkspace()->create(['email' => 'grace@example.com']);
        $laptop = new Device($this);
        $phone = new Device($this);
        $grace = new Device($this);
        $laptop->signIn('ada@example.com')->assertOk();
        $phone->signIn('ada@example.com')->assertOk();
        $grace->signIn('grace@example.com')->assertOk();
        $user = User::query()->where('email', 'ada@example.com')->sole();
        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(2);

        $laptop->request('POST', '/api/v1/me/deletion-code')->assertOk();
        $laptop->request('DELETE', '/api/v1/me', ['code' => lastSentCode()])->assertNoContent();

        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);

        // Signed out here, and nowhere left to be signed in.
        $laptop->visit('/app')->assertRedirect(route('login'));
        $laptop->request('GET', '/api/v1/me')->assertUnauthorized();
        $phone->request('GET', '/api/v1/me')->assertUnauthorized();
        $phone->withoutSession()->request('GET', '/api/v1/me')->assertUnauthorized();
        $grace->request('GET', '/api/v1/me')->assertOk();
    });

    it('tells the browser to forget the "remember me" cookie', function (): void {
        User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $laptop = new Device($this);
        $laptop->signIn('ada@example.com')->assertOk();
        expect($laptop->rememberCookieName())->not->toBeNull();

        $laptop->request('POST', '/api/v1/me/deletion-code')->assertOk();
        $laptop->request('DELETE', '/api/v1/me', ['code' => lastSentCode()])->assertNoContent();

        expect($laptop->rememberCookieName())->toBeNull();
    });

    it('tells the person once, on the next page, that the account is deleted', function (): void {
        User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $laptop = new Device($this);
        $laptop->signIn('ada@example.com')->assertOk();
        $laptop->request('POST', '/api/v1/me/deletion-code')->assertOk();
        $laptop->request('DELETE', '/api/v1/me', ['code' => lastSentCode()])->assertNoContent();

        // The page the browser goes to: signed out, with the notice for the front to show as a toast.
        $laptop->visit('/')->assertOk()
            ->assertSee('x-data="flashNotice"', false)
            ->assertSee('data-message="Your account has been deleted."', false);

        // Said once: a reload, or any other page, does not say it again.
        $laptop->visit('/')->assertOk()->assertDontSee('flashNotice');
    });

    it('says it in the language of the person, which the browser keeps in a cookie', function (): void {
        User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com', 'locale' => 'fr']);
        $laptop = new Device($this);
        $laptop->signIn('ada@example.com')->assertOk();
        $laptop->request('POST', '/api/v1/me/deletion-code')->assertOk();
        $laptop->request('DELETE', '/api/v1/me', ['code' => lastSentCode()])->assertNoContent();

        $laptop->visit('/')->assertSee('data-message="Votre compte a été supprimé."', false);
    });

    it('tells nothing when nothing was deleted', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $code = deletionCodeFor($this, $user);

        forgetPreviousRequest($this);
        confirmDeletion($this, $user, wrongCode($code))->assertUnprocessable();

        $this->get('/')->assertOk()->assertDontSee('flashNotice');
    });

    it('accepts the code with the spaces people paste around it', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $code = deletionCodeFor($this, $user);
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, ' '.substr($code, 0, 3).' '.substr($code, 3).' ')->assertNoContent();

        expect(User::query()->count())->toBe(0);
    });

    it('lets an account that shares a workspace with another owner leave it', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $partner = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($user, 'Shared');
        $shared->members()->attach($partner->id, ['role' => 'owner']);
        $code = deletionCodeFor($this, $user);
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, $code)->assertNoContent();

        expect(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue()
            ->and(WorkspaceMember::query()->where('workspace_id', $shared->id)->pluck('user_id')->all())->toBe([$partner->id]);
    });

    it('lets an administrator go when another one remains', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        User::factory()->admin()->create();
        $code = deletionCodeFor($this, $admin);
        forgetPreviousRequest($this);

        confirmDeletion($this, $admin, $code)->assertNoContent();

        expect(User::query()->where('is_admin', true)->count())->toBe(1);
    });

    it('is atomic: when anything fails, nothing is deleted', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $workspace = $user->workspaces()->sole();
        $user->createToken('script');
        $code = deletionCodeFor($this, $user);
        forgetPreviousRequest($this);
        Event::listen('eloquent.deleting: '.User::class, function (): never {
            throw new RuntimeException('The database went away.');
        });

        try {
            confirmDeletion($this, $user, $code)->assertServerError();
        } finally {
            Event::forget('eloquent.deleting: '.User::class);
        }

        expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
            ->and(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue()
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->exists())->toBeTrue();
    });

    it('is for somebody who is signed in, and for the web front', function (): void {
        $this->deleteJson('/api/v1/me', ['code' => '123456'], frontend())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->deleteJson('/api/v1/me', ['code' => '123456'])->assertForbidden()->assertJsonPath('code', 'not_stateful');
    });
});

describe('the code', function (): void {
    it('is refused when it is wrong, saying how many attempts are left', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $right = deletionCodeFor($this, $user);
        $wrong = wrongCode($right);

        foreach ([4, 3] as $left) {
            forgetPreviousRequest($this);
            confirmDeletion($this, $user, $wrong)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'deletion_code_wrong')
                ->assertJsonPath('errors.code.0', trans_choice('Incorrect code. :count attempt left.|Incorrect code. :count attempts left.', $left));
        }

        expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
            ->and(AccountDeletionCode::query()->sole()->attempts)->toBe(2);
    });

    it('is burnt after five wrong ones: even the right code is then refused, until a new one is asked for', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $right = deletionCodeFor($this, $user);

        foreach (range(1, 5) as $_) {
            forgetPreviousRequest($this);
            confirmDeletion($this, $user, wrongCode($right))->assertUnprocessable();
        }

        // (The confirmation is throttled per minute: let the minute pass, it is the code that is being tested.)
        $this->travel(2)->minutes();
        forgetPreviousRequest($this);
        confirmDeletion($this, $user, $right)->assertUnprocessable()->assertJsonPath('code', 'deletion_code_exhausted');
        expect(User::query()->whereKey($user->id)->exists())->toBeTrue();

        // Asking again gives a fresh code and a fresh allowance.
        forgetPreviousRequest($this);
        $fresh = deletionCodeFor($this, $user);
        forgetPreviousRequest($this);
        confirmDeletion($this, $user, $fresh)->assertNoContent();
    });

    it('counts its wrong attempts apart from the sign-in codes, in both directions', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $right = deletionCodeFor($this, $user);

        foreach (range(1, 3) as $_) {
            forgetPreviousRequest($this);
            confirmDeletion($this, $user, wrongCode($right))->assertUnprocessable();
        }

        // The address collected no wrong sign-in code, and the code of the sign-in is not touched.
        expect(loginFailureCount('ada@example.com'))->toBe(0)
            ->and(LoginCode::query()->count())->toBe(0);

        // Wrong sign-in codes do not burn the deletion code either.
        forgetPreviousRequest($this);
        $signIn = receiveCode($this, 'ada@example.com');

        foreach (range(1, 4) as $_) {
            forgetPreviousRequest($this);
            typeCode($this, 'ada@example.com', wrongCode($signIn))->assertUnprocessable();
        }

        expect(AccountDeletionCode::query()->sole()->attempts)->toBe(3);
        forgetPreviousRequest($this);
        confirmDeletion($this, $user, $right)->assertNoContent();
    });

    it('is not the sign-in code, nor the code of somebody else', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $other = User::factory()->withPersonalWorkspace()->create(['email' => 'grace@example.com']);
        $signIn = receiveCode($this, 'ada@example.com');
        $forOther = deletionCodeFor($this, $other);
        forgetPreviousRequest($this);
        $mine = deletionCodeFor($this, $user);

        // A sign-in code is no good to delete the account (unless it happens to be the same six digits).
        if ($signIn !== $mine) {
            forgetPreviousRequest($this);
            confirmDeletion($this, $user, $signIn)->assertUnprocessable()->assertJsonPath('code', 'deletion_code_wrong');
        }

        // Nor is the code that was mailed to somebody else.
        if ($forOther !== $mine) {
            forgetPreviousRequest($this);
            confirmDeletion($this, $user, $forOther)->assertUnprocessable()->assertJsonPath('code', 'deletion_code_wrong');
        }

        // And the deletion code does not sign anybody in.
        forgetPreviousRequest($this);
        typeCode($this, 'ada@example.com', $mine)->assertUnprocessable();

        expect(User::query()->count())->toBe(2);
    });

    it('has to be asked for first, and expires', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();

        confirmDeletion($this, $user, '123456')->assertUnprocessable()->assertJsonPath('code', 'deletion_code_expired');

        forgetPreviousRequest($this);
        $code = deletionCodeFor($this, $user);
        $this->travel(11)->minutes();
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, $code)->assertUnprocessable()->assertJsonPath('code', 'deletion_code_expired');
        expect(User::query()->whereKey($user->id)->exists())->toBeTrue()->and(AccountDeletionCode::query()->count())->toBe(0);
    });

    it('is valid for the whole ten minutes', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $code = deletionCodeFor($this, $user);
        $this->travel(9)->minutes();
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, $code)->assertNoContent();
    });

    it('is a code of six digits', function (mixed $code): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        deletionCodeFor($this, $user);
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, $code)->assertUnprocessable()->assertJsonValidationErrors('code');

        expect(AccountDeletionCode::query()->sole()->attempts)->toBe(0);
    })->with(['missing' => [null], 'empty' => [''], 'short' => ['12345'], 'long' => ['1234567'], 'letters' => ['abcdef'], 'a number' => [123456], 'a list' => [['123456']]]);

    it('is not about the numbers typed too often: the confirmation is throttled', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $right = deletionCodeFor($this, $user);
        $limit = config()->integer('auth.account_deletion.verify_per_minute');

        foreach (range(1, $limit) as $_) {
            forgetPreviousRequest($this);
            confirmDeletion($this, $user, wrongCode($right));
        }

        forgetPreviousRequest($this);
        confirmDeletion($this, $user, $right)->assertTooManyRequests();
        expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
    });
});

describe('what stops the deletion', function (): void {
    it('is the last administrator of the instance, who keeps their code for later', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();
        $other = User::factory()->admin()->create();

        // The code is asked for while another administrator remains; that one leaves before the code is typed.
        $code = deletionCodeFor($this, $admin);
        $other->delete();
        forgetPreviousRequest($this);

        confirmDeletion($this, $admin, $code)->assertStatus(409)->assertJsonPath('code', 'last_administrator');
        expect(User::query()->whereKey($admin->id)->exists())->toBeTrue()
            ->and(AccountDeletionCode::query()->exists())->toBeTrue();

        // Once somebody else is an administrator, the same code does it.
        User::factory()->admin()->create();
        forgetPreviousRequest($this);
        confirmDeletion($this, $admin, $code)->assertNoContent();
    });

    it('is being the only owner of a workspace that others belong to, with the list of them', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->withPersonalWorkspace()->create();
        $first = app(Workspaces::class)->create($user, 'First');
        $second = app(Workspaces::class)->create($user, 'Second');
        $alone = app(Workspaces::class)->create($user, 'Alone');
        $together = app(Workspaces::class)->create($user, 'Together');
        $code = deletionCodeFor($this, $user);

        // People join after the code was asked for: the deletion is judged again when it is confirmed.
        $first->members()->attach($guest->id, ['role' => 'viewer']);
        $second->members()->attach($guest->id, ['role' => 'editor']);
        $together->members()->attach($guest->id, ['role' => 'owner']);
        forgetPreviousRequest($this);

        $response = confirmDeletion($this, $user, $code)->assertStatus(409)->assertJsonPath('code', 'sole_owner_of_shared_workspaces');

        expect($response->json('workspaces'))->toBe([['id' => $first->ulid, 'name' => 'First'], ['id' => $second->ulid, 'name' => 'Second']])
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
            ->and(Workspace::query()->whereIn('id', [$first->id, $second->id, $alone->id, $together->id])->count())->toBe(4)
            ->and(AccountDeletionCode::query()->exists())->toBeTrue();
    });

    it('is cleared by handing a workspace over, with the code asked for before', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($user, 'Shared');
        $code = deletionCodeFor($this, $user);
        $shared->members()->attach($guest->id, ['role' => 'viewer']);
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, $code)->assertStatus(409);

        $shared->members()->updateExistingPivot($guest->id, ['role' => 'owner']);
        forgetPreviousRequest($this);
        confirmDeletion($this, $user, $code)->assertNoContent();

        expect(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue();
    });

    it('does not stop an account that merely belongs to the workspaces of others as a viewer', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $owner = User::factory()->withPersonalWorkspace()->create();
        app(Workspaces::class)->create($owner, 'Hers')->members()->attach($user->id, ['role' => 'viewer']);
        $code = deletionCodeFor($this, $user);
        forgetPreviousRequest($this);

        confirmDeletion($this, $user, $code)->assertNoContent();
    });
});

describe('an account that is gone when the deletion is confirmed', function (): void {
    it('is a 404, not a deletion that did not happen', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $code = deletionCodeFor($this, $user);
        $gone = false;
        DB::listen(function ($query) use ($user, &$gone): void {
            // As soon as the deletion starts to lock what it needs, somebody else deletes the account.
            if (! $gone && stripos($query->sql, 'for update') !== false && stripos($query->sql, 'login_codes') !== false) {
                $gone = true;
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        confirmDeletion($this, $user, $code)
            ->assertNotFound()
            ->assertJsonPath('code', 'account_not_found')
            ->assertJsonPath('message', 'This account no longer exists.');

        // (The simulated deletion ran in the transaction of the request, which the refusal rolled back: the point is
        // the answer, and that the deletion was not reported as done.)
        expect($gone)->toBeTrue();
    });

    it('is told in the language of the request', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $code = deletionCodeFor($this, $user);
        DB::listen(function ($query) use ($user): void {
            if (stripos($query->sql, 'for update') !== false && stripos($query->sql, 'login_codes') !== false) {
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        $this->actingAs($user)->deleteJson('/api/v1/me', ['code' => $code], frontend(['Accept-Language' => 'fr']))
            ->assertNotFound()
            ->assertJsonPath('message', "Ce compte n'existe plus.");
    });

    it('signs this device out all the same: the account it was signed in to is gone', function (): void {
        User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $laptop = new Device($this);
        $laptop->signIn('ada@example.com')->assertOk();
        $laptop->request('POST', '/api/v1/me/deletion-code')->assertOk();
        $code = lastSentCode();
        $user = User::query()->where('email', 'ada@example.com')->sole();
        $gone = false;
        DB::listen(function ($query) use ($user, &$gone): void {
            if (! $gone && stripos($query->sql, 'for update') !== false && stripos($query->sql, 'login_codes') !== false) {
                $gone = true;
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        $laptop->request('DELETE', '/api/v1/me', ['code' => $code])->assertNotFound()->assertJsonPath('code', 'account_not_found');

        // (The simulated deletion was rolled back with the request, so the account is still there for the session to
        // find: what ends it is the answer itself.)
        expect($gone)->toBeTrue()
            ->and($laptop->rememberCookieName())->toBeNull();
        $laptop->request('GET', '/api/v1/me')->assertUnauthorized();
    });

    it('is not what another record that was not found means: that is a failure, not an account that is gone', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $code = deletionCodeFor($this, $user);
        Workspace::deleting(fn () => throw (new ModelNotFoundException)->setModel(Workspace::class, [1]));
        $this->withoutExceptionHandling();

        expect(fn () => confirmDeletion($this, $user, $code))->toThrow(ModelNotFoundException::class)
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
    });
});

describe('deleting the account through the model, whoever asks', function (): void {
    it('leaves no workspace behind, and deletes the ones nobody else belongs to one at a time, with their events', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = User::factory()->withPersonalWorkspace()->create();
        $mine = app(Workspaces::class)->create($user, 'Mine');
        $shared = app(Workspaces::class)->create($other, 'Shared');
        $shared->members()->attach($user->id, ['role' => 'editor']);
        $personal = $user->workspaces()->orderBy('workspace_user.id')->firstOrFail();
        $user->createToken('script');
        $deleted = [];
        Event::listen('eloquent.deleted: '.Workspace::class, function (Workspace $workspace) use (&$deleted): void {
            $deleted[] = $workspace->id;
        });

        $user->delete();

        expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
            ->and($deleted)->toBe([$personal->id, $mine->id])
            ->and(Workspace::query()->whereDoesntHave('members')->count())->toBe(0)
            ->and(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue()
            ->and(WorkspaceMember::query()->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->exists())->toBeFalse();
    });

    it('refuses an account that is the only owner of a shared workspace, and changes nothing', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($user, 'Shared');
        $shared->members()->attach($guest->id, ['role' => 'viewer']);
        $personal = $user->workspaces()->orderBy('workspace_user.id')->firstOrFail();

        try {
            $user->delete();
        } catch (AccountDeletionRefused $refusal) {
            expect($refusal->reason)->toBe(AccountDeletionRefused::SOLE_OWNER_OF_SHARED_WORKSPACES)
                ->and(array_map(fn (Workspace $workspace): int => $workspace->id, $refusal->workspaces))->toBe([$shared->id])
                ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
                ->and(Workspace::query()->whereIn('id', [$personal->id, $shared->id])->count())->toBe(2);

            return;
        }

        throw new RuntimeException('The account was deleted.');
    });

    it('refuses the last administrator', function (): void {
        $admin = User::factory()->admin()->withPersonalWorkspace()->create();

        expect(fn () => $admin->delete())->toThrow(AccountDeletionRefused::class)
            ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue()
            ->and($admin->workspaces()->count())->toBe(1);
    });

    it('lets the last co-owner take the shared workspace with them: one after the other, none is left without a member', function (): void {
        $ada = User::factory()->withPersonalWorkspace()->create();
        $grace = User::factory()->withPersonalWorkspace()->create();
        $shared = app(Workspaces::class)->create($ada, 'Shared');
        $shared->members()->attach($grace->id, ['role' => 'owner']);

        $ada->delete();

        expect(Workspace::query()->whereKey($shared->id)->exists())->toBeTrue()
            ->and(WorkspaceMember::query()->where('workspace_id', $shared->id)->pluck('user_id')->all())->toBe([$grace->id]);

        $grace->delete();

        expect(Workspace::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0);
    });

    it('says so when the account is already gone, instead of reporting a deletion that deleted nothing', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $bystander = User::factory()->withPersonalWorkspace()->create();
        $stale = User::query()->findOrFail($user->id);
        $user->delete();

        expect(fn () => $stale->delete())->toThrow(ModelNotFoundException::class)
            ->and(User::query()->pluck('id')->all())->toBe([$bystander->id])
            ->and(Workspace::query()->count())->toBe(1);
    });

    it('is not run again by itself inside another transaction: that one is, as a whole', function (): void {
        // A deadlock rolls the outermost transaction back whole; running the deletion again alone would run it outside
        // of what was rolled back (see DeadlockRetries). So it surfaces, for whoever opened the transaction to retry.
        $user = User::factory()->withPersonalWorkspace()->create();
        $tries = 0;

        User::deleting(function () use (&$tries): void {
            $tries++;

            throw lostDeadlock();
        });

        expect(fn () => DB::transaction(fn () => $user->delete()))->toThrow(DeadlockException::class)
            ->and($tries)->toBe(1);
    });

    it('locks the sign-in code of the stored address, not the one a stale copy of the account spells', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $stale = User::query()->findOrFail($user->id);
        $stale->setRawAttributes([...$stale->getAttributes(), 'email' => 'Ada@Example.COM']);
        $locked = [];
        DB::listen(function ($query) use (&$locked): void {
            if (stripos($query->sql, 'for update') !== false && stripos($query->sql, 'login_codes') !== false) {
                $locked[] = $query->bindings[0];
            }
        });

        $stale->delete();

        expect($locked)->toBe(['ada@example.com'])
            ->and(User::query()->whereKey($user->id)->exists())->toBeFalse();
    });

    it('takes the locks in the global order: the sign-in code of the address, then the accounts, then the workspaces, then the memberships', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create(['email' => 'ada@example.com']);
        $locked = [];
        DB::listen(function ($query) use (&$locked): void {
            if (stripos($query->sql, 'for update') !== false && preg_match('/\bfrom\s+["`]?(\w+)/i', $query->sql, $table) === 1) {
                $locked[] = $table[1];
            }
        });

        $user->delete();

        // Signing in locks the sign-in code before the account (VerifyCodeController): deleting must not go the other
        // way round, or the two wait for each other. (Every table appears once, at its first lock.)
        expect(array_values(array_unique($locked)))->toBe(['login_codes', 'users', 'workspaces', 'workspace_user']);
    });

    it('deletes nothing when one of the workspaces fails to go', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        app(Workspaces::class)->create($user, 'Second');
        $seen = 0;
        Event::listen('eloquent.deleting: '.Workspace::class, function () use (&$seen): void {
            if (++$seen === 2) {
                throw new RuntimeException('The disk went away.');
            }
        });

        try {
            $user->delete();
        } catch (RuntimeException) {
            // expected
        }

        expect($seen)->toBe(2)
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
            ->and($user->workspaces()->count())->toBe(2);
    });
});
