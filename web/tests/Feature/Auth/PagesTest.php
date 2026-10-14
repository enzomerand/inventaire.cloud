<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('the login page', function (): void {
    it('is served to visitors, with the endpoints its script needs', function (): void {
        $this->get(route('login'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="login"', false)
            ->assertSee('data-code-url="/api/v1/auth/code"', false)
            ->assertSee('data-verify-url="/api/v1/auth/verify"', false)
            ->assertSee('data-app-url="/app"', false)
            ->assertSee('Send me a code')
            ->assertSee('is valid for 10 minutes', false);
    });

    it('is translated', function (): void {
        $this->get(route('login'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Envoyez-moi un code')
            ->assertSee('Utiliser une autre adresse');
    });

    it('sends somebody who is signed in to their space', function (): void {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('app'));
    });

    it('is named login, which is where the scripts send a signed-out visitor', function (): void {
        expect(route('login', absolute: false))->toBe('/login');

        $this->get(route('login'))->assertSee('<meta name="login-url" content="/login">', false);
    });

    it('does not offer to sign in twice: the header has no sign-in button on it', function (): void {
        $page = $this->get(route('login'), ['Accept-Language' => 'en'])->assertOk();

        expect(substr_count($page->getContent(), 'href="'.route('login').'"'))->toBe(0);
    });
});

describe('the space of a signed-in user', function (): void {
    it('is shown to them, with an empty state saying that inventories come soon', function (): void {
        $this->actingAs(User::factory()->create(['name' => 'Ada Lovelace']))
            ->get(route('app'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('No inventory yet')
            ->assertSee('Inventories are coming soon')
            ->assertSee('class="empty"', false);
    });

    it('sends a visitor to the login page', function (): void {
        $this->get(route('app'))->assertRedirect(route('login'));
    });

    it('answers the API in JSON instead of redirecting, whatever the page does', function (): void {
        $this->call('GET', '/api/v1/me')->assertUnauthorized()->assertHeaderMissing('Location');
    });

    it('is translated', function (): void {
        $this->actingAs(User::factory()->create())->get(route('app'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Aucun inventaire pour')
            ->assertSee('Mon espace');
    });
});

describe('the header', function (): void {
    it('offers a visitor to sign in', function (): void {
        $this->get(route('home'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('Sign in')
            ->assertDontSee('x-data="accountMenu"', false)
            ->assertDontSee('Open my space');
    });

    it('shows the user menu to somebody signed in: name, address and sign-out', function (): void {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->actingAs($user)->get(route('home'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="accountMenu"', false)
            ->assertSee('data-logout-url="/api/v1/auth/logout"', false)
            ->assertSee('data-home-url="/"', false)
            ->assertSee('Ada Lovelace')
            ->assertSee('ada@example.com')
            ->assertSee('Sign out')
            ->assertDontSee('href="'.route('login').'"', false);
    });

    it('escapes the name of the user', function (): void {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get(route('app'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    });

    it('stays server-rendered and public: the home page never redirects', function (): void {
        $this->get(route('home'))->assertOk();
    });

    it('links the home page to the space of a signed-in user', function (): void {
        $this->actingAs(User::factory()->create())->get(route('home'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('href="'.route('app').'"', false)
            ->assertSee('Open my space');
    });
});

describe('the install wizard', function (): void {
    it('shows neither a sign-in button nor an account menu: there are no accounts, or even a database, yet', function (): void {
        $this->bootInstaller(installed: false);
        $this->get('/'); // the first access creates the setup token

        $this->get('/install')
            ->assertOk()
            ->assertDontSee('accountMenu')
            ->assertDontSee('href="/login"', false);
    });
});

describe('the scripts', function (): void {
    it('get the messages the sign-in page shows', function (): void {
        $page = $this->get(route('login'), ['Accept-Language' => 'en'])->assertOk();

        preg_match('#<script type="application/json" id="client-messages">(.*?)</script>#s', $page->getContent(), $match);
        $messages = json_decode($match[1], true);

        expect($messages)->toHaveKeys(['login.error.not_stateful', 'login.error.throttled', 'login.notice.code_resent'])
            ->and($messages['login.error.throttled'])->toContain(':seconds');
    });
});
