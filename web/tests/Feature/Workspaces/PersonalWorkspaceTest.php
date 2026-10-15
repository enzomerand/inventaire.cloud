<?php

declare(strict_types=1);

use App\Auth\Accounts;
use App\Auth\AdministratorChange;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The only workspace of an account, with what it must hold.
 */
function personalWorkspaceOf(User $user): Workspace
{
    $user->refresh();
    $workspace = $user->workspaces()->sole();

    expect($workspace->pivot->role)->toBe(WorkspaceRole::Owner)
        ->and($user->current_workspace_id)->toBe($workspace->id);

    return $workspace;
}

describe('creating an account', function (): void {
    it('creates its personal workspace, owned by the account and current', function (): void {
        [$user, $created] = app(Accounts::class)->register('Ada@Example.com', 'Ada Lovelace', 'en');

        expect($created)->toBeTrue()
            ->and(personalWorkspaceOf($user)->name)->toBe('Personal space')
            ->and(Workspace::query()->count())->toBe(1);
    });

    it('names it in the language of the account', function (string $locale, string $name): void {
        [$user] = app(Accounts::class)->register('ada@example.com', 'Ada', $locale);

        expect(personalWorkspaceOf($user)->name)->toBe($name);
    })->with([
        'English' => ['en', 'Personal space'],
        'French' => ['fr', 'Espace personnel'],
    ]);

    it('names it in the language of the request while the account has none', function (): void {
        app()->setLocale('en');

        [$user] = app(Accounts::class)->register('ada@example.com', 'Ada', null);

        expect(personalWorkspaceOf($user)->name)->toBe('Personal space');
    });

    it('does not use the name of the person: it is a label', function (): void {
        [$user] = app(Accounts::class)->register('ada@example.com', 'Ada Lovelace', 'fr');

        expect(personalWorkspaceOf($user)->name)->not->toContain('Ada');
    });

    it('creates exactly one when two requests race for the same address', function (): void {
        [$first] = app(Accounts::class)->register('ada@example.com', 'Ada', 'en');
        [$second, $created] = app(Accounts::class)->register('ADA@example.com', 'Ada again', 'en');

        expect($created)->toBeFalse()
            ->and($second->is($first))->toBeTrue()
            ->and(Workspace::query()->count())->toBe(1)
            ->and(WorkspaceMember::query()->count())->toBe(1);
    });

    it('is part of the sign-up through the API', function (): void {
        $code = receiveCode($this, 'new@example.com');

        typeCode($this, 'new@example.com', $code, ['name' => 'New Person'])->assertCreated();

        $user = User::query()->where('email', 'new@example.com')->sole();
        expect(personalWorkspaceOf($user)->name)->toBe('Personal space');
    });
});

describe('signing in', function (): void {
    it('gives an account without any workspace its personal one', function (): void {
        $user = User::factory()->speaking('en')->create(['email' => 'old@example.com']);
        expect($user->workspaces()->count())->toBe(0);

        typeCode($this, 'old@example.com', receiveCode($this, 'old@example.com'))->assertOk();

        expect(personalWorkspaceOf($user)->name)->toBe('Personal space');
    });

    it('leaves an account that has workspaces alone', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $mine = app(Workspaces::class)->createPersonal($user);
        $other = app(Workspaces::class)->create($user, 'Shop');
        app(Workspaces::class)->makeCurrent($user, $mine);

        typeCode($this, 'ada@example.com', receiveCode($this, 'ada@example.com'))->assertOk();

        expect($user->workspaces()->count())->toBe(2)
            ->and($user->fresh()->current_workspace_id)->toBe($mine->id)
            ->and($other->exists)->toBeTrue();
    });
});

describe('making an administrator', function (): void {
    it('creates the personal workspace of a new one', function (): void {
        [$user, $change] = app(Accounts::class)->administrator('jane@example.test', 'Jane');

        expect($change)->toBe(AdministratorChange::Created);
        personalWorkspaceOf($user);
    });

    it('gives one to an existing account that has none when it is promoted', function (): void {
        $user = User::factory()->create(['email' => 'jane@example.test']);

        [, $change] = app(Accounts::class)->administrator('jane@example.test', 'Jane');

        expect($change)->toBe(AdministratorChange::Promoted);
        personalWorkspaceOf($user);
    });

    it('gives one to an administrator that has none, without reporting a change', function (): void {
        $user = User::factory()->admin()->create(['email' => 'jane@example.test']);

        [, $change] = app(Accounts::class)->administrator('jane@example.test', 'Jane');

        expect($change)->toBe(AdministratorChange::Unchanged);
        personalWorkspaceOf($user);
    });

    it('is run again when the database picks the creation of the account as the loser of a deadlock', function (): void {
        $tries = 0;
        User::creating(function () use (&$tries): void {
            if (++$tries === 1) {
                throw lostDeadlock();
            }
        });

        [$user, $change] = app(Accounts::class)->administrator('jane@example.test', 'Jane');

        expect($change)->toBe(AdministratorChange::Created)
            ->and($tries)->toBe(2)
            ->and(User::query()->count())->toBe(1);
        personalWorkspaceOf($user);
    });

    it('is run again when the database picks the promotion of the account as the loser of a deadlock', function (): void {
        $user = User::factory()->create(['email' => 'jane@example.test']);
        $tries = 0;
        User::updating(function () use (&$tries): void {
            if (++$tries === 1) {
                throw lostDeadlock();
            }
        });

        [, $change] = app(Accounts::class)->administrator('jane@example.test', 'Jane');

        expect($change)->toBe(AdministratorChange::Promoted)
            ->and($user->refresh()->is_admin)->toBeTrue();
        personalWorkspaceOf($user);
    });

    it('creates no second workspace when it is run again', function (): void {
        app(Accounts::class)->administrator('jane@example.test', 'Jane');
        app(Accounts::class)->administrator('jane@example.test', 'Jane');
        app(Accounts::class)->administrator('JANE@example.test', 'Jane');

        expect(Workspace::query()->count())->toBe(1);
    });

    it('is done by inventaire:make-admin too', function (): void {
        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--name' => 'Jane', '--no-interaction' => true])->assertSuccessful();
        $existing = User::factory()->create(['email' => 'john@example.test']);
        $this->artisan('inventaire:make-admin', ['email' => 'john@example.test', '--no-interaction' => true])->assertSuccessful();

        personalWorkspaceOf(User::query()->where('email', 'jane@example.test')->sole());
        personalWorkspaceOf($existing);
    });
});

describe('ensuring a workspace', function (): void {
    it('leaves an account that belongs to a workspace of somebody else alone', function (): void {
        $owner = User::factory()->withPersonalWorkspace()->create();
        $guest = User::factory()->create();
        $owner->workspaces()->sole()->members()->attach($guest->id, ['role' => 'viewer']);

        app(Workspaces::class)->ensure($guest);

        expect($guest->workspaces()->count())->toBe(1)
            ->and(Workspace::query()->count())->toBe(1);
    });
});

describe('the migration for existing accounts', function (): void {
    beforeEach(function (): void {
        $this->backfill = require database_path('migrations/2026_10_15_100100_give_every_user_a_workspace.php');
    });

    it('gives every account without a workspace its personal one, in its language', function (): void {
        $english = User::factory()->speaking('en')->create();
        $french = User::factory()->speaking('fr')->create();
        $unknown = User::factory()->create();
        config(['app.locale' => 'en']);

        $this->backfill->up();

        expect(personalWorkspaceOf($english)->name)->toBe('Personal space')
            ->and(personalWorkspaceOf($french)->name)->toBe('Espace personnel')
            ->and(personalWorkspaceOf($unknown)->name)->toBe('Personal space');
    });

    it('is idempotent and leaves the accounts that have workspaces alone', function (): void {
        $legacy = User::factory()->create();
        $current = User::factory()->withPersonalWorkspace()->create();
        $before = Workspace::query()->pluck('ulid', 'id')->all();

        $this->backfill->up();
        $this->backfill->up();

        expect(Workspace::query()->count())->toBe(2)
            ->and(WorkspaceMember::query()->count())->toBe(2)
            ->and(personalWorkspaceOf($legacy)->exists)->toBeTrue()
            ->and(array_intersect_key(Workspace::query()->pluck('ulid', 'id')->all(), $before))->toBe($before)
            ->and($current->workspaces()->count())->toBe(1);
    });

    it('handles more accounts than one batch', function (): void {
        $now = now();
        DB::table('users')->insert(array_map(fn (int $n): array => [
            'name' => "User {$n}", 'email' => "user{$n}@example.test", 'remember_token' => null, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 250)));

        $this->backfill->up();

        expect(Workspace::query()->count())->toBe(250)
            ->and(User::query()->whereNull('current_workspace_id')->count())->toBe(0)
            ->and(WorkspaceMember::query()->distinct()->count('user_id'))->toBe(250);
    });
});
