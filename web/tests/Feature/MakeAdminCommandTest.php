<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('inventaire:make-admin', function (): void {
    beforeEach(function (): void {
        // The messages are asserted on their English source text.
        $this->useLanguage('en');
    });

    it('creates the administrator account of an unknown address', function (): void {
        $this->artisan('inventaire:make-admin', ['email' => 'Jane@Example.test', '--name' => 'Jane Doe', '--no-interaction' => true])
            ->expectsOutputToContain('administrator account of jane@example.test was created')
            ->expectsOutputToContain('no password')
            ->assertSuccessful();

        $user = User::query()->sole();

        expect($user->email)->toBe('jane@example.test')
            ->and($user->name)->toBe('Jane Doe')
            ->and($user->is_admin)->toBeTrue()
            ->and($user->locale)->toBeNull();
    });

    it('promotes an existing account and keeps its name', function (): void {
        $user = User::factory()->create(['name' => 'Already Here', 'email' => 'jane@example.test']);

        $this->artisan('inventaire:make-admin', ['email' => 'JANE@example.test', '--name' => 'Another Name', '--no-interaction' => true])
            ->expectsOutputToContain('jane@example.test is now an administrator')
            ->assertSuccessful();

        expect(User::query()->count())->toBe(1)
            ->and($user->fresh()->is_admin)->toBeTrue()
            ->and($user->fresh()->name)->toBe('Already Here');
    });

    it('needs no name to promote an account', function (): void {
        User::factory()->create(['email' => 'jane@example.test']);

        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--no-interaction' => true])->assertSuccessful();

        expect(User::query()->sole()->is_admin)->toBeTrue();
    });

    it('can be run again: nothing changes the second time', function (): void {
        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--name' => 'Jane Doe', '--no-interaction' => true])->assertSuccessful();
        $updatedAt = User::query()->sole()->updated_at;

        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--name' => 'Jane Doe', '--no-interaction' => true])
            ->expectsOutputToContain('jane@example.test is already an administrator')
            ->assertSuccessful();

        expect(User::query()->count())->toBe(1)->and(User::query()->sole()->updated_at->equalTo($updatedAt))->toBeTrue();
    });

    it('leaves the other accounts alone', function (): void {
        $other = User::factory()->create(['email' => 'other@example.test']);

        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--name' => 'Jane Doe', '--no-interaction' => true])->assertSuccessful();

        expect($other->fresh()->is_admin)->toBeFalse();
    });

    it('asks for the name of an account it has to create when a person is there to answer', function (): void {
        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test'])
            ->expectsQuestion('Name', 'Jane Doe')
            ->assertSuccessful();

        expect(User::query()->sole()->name)->toBe('Jane Doe');
    });

    it('refuses to create an account without a name when nobody can be asked', function (): void {
        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--no-interaction' => true])
            ->expectsOutputToContain('give its name with --name')
            ->assertExitCode(2);

        expect(User::query()->count())->toBe(0);
    });

    it('refuses an address that is not one', function (string $email): void {
        $this->artisan('inventaire:make-admin', ['email' => $email, '--name' => 'Jane', '--no-interaction' => true])->assertExitCode(2);

        expect(User::query()->count())->toBe(0);
    })->with(['no domain' => ['jane@'], 'not an address' => ['jane'], 'spaces' => ['jane doe@example.test']]);

    it('stores the address of a lookalike domain in ASCII, so that it is not taken for the real one', function (): void {
        User::factory()->create(['email' => 'jane@gmail.com']);

        $this->artisan('inventaire:make-admin', ['email' => 'Jane@Gmäil.com', '--name' => 'Mallory', '--no-interaction' => true])
            ->expectsOutputToContain('administrator account of jane@xn--gmil-moa.com was created')
            ->assertSuccessful();

        expect(User::query()->orderBy('id')->pluck('is_admin', 'email')->all())->toBe(['jane@gmail.com' => false, 'jane@xn--gmil-moa.com' => true]);
    });

    it('refuses an accent in the local part, with the reason', function (): void {
        $this->artisan('inventaire:make-admin', ['email' => 'jöhn@example.test', '--name' => 'John', '--no-interaction' => true])
            ->expectsOutputToContain('accents or other special letters')
            ->assertExitCode(2);

        expect(User::query()->count())->toBe(0);
    });

    it('refuses to run on an instance that is not installed', function (): void {
        $this->bootInstaller(installed: false);

        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--name' => 'Jane', '--no-interaction' => true])
            ->expectsOutputToContain('not installed yet')
            ->assertFailed();
    });

    it('speaks French when the application does', function (): void {
        $this->useLanguage('fr');

        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--name' => 'Jane', '--no-interaction' => true])
            ->expectsOutputToContain('Le compte administrateur de jane@example.test a été créé')
            ->assertSuccessful();
    });

    it('gives an account that can sign in with a code, as an administrator', function (): void {
        $this->artisan('inventaire:make-admin', ['email' => 'jane@example.test', '--name' => 'Jane Doe', '--no-interaction' => true])->assertSuccessful();

        $this->postJson('/api/v1/auth/code', ['email' => 'jane@example.test'], frontend())->assertOk();
        $this->postJson('/api/v1/auth/verify', ['email' => 'jane@example.test', 'code' => lastSentCode()], frontend())
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.is_admin', true);

        // Signing in with the code proves the address.
        expect(User::query()->sole()->email_verified_at)->not->toBeNull();
    });
});
