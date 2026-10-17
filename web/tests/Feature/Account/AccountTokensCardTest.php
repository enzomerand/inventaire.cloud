<?php

declare(strict_types=1);

use App\Auth\TokenAbility;
use App\Models\User;
use App\View\Components\ClientMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The messages the layout hands to the scripts of a page, as the page embeds them.
 *
 * @return array<string, string>
 */
function clientMessagesOf(TestResponse $page): array
{
    preg_match('#<script type="application/json" id="client-messages">(.*?)</script>#s', $page->getContent(), $match);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

describe('the API tokens card of the account page', function (): void {
    it('comes after the security card, with the endpoint its script needs', function (): void {
        $user = User::factory()->speaking('en')->withPersonalWorkspace()->create();

        $page = $this->actingAs($user)->get(route('app.account'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="accountTokens"', false)
            ->assertSee('data-url="/api/v1/me/tokens"', false)
            ->assertSee('API tokens');

        $html = $page->getContent();

        expect(strpos($html, 'x-data="accountSecurity"'))
            ->toBeLessThan(strpos($html, 'x-data="accountTokens"'))
            ->and(strpos($html, 'x-data="accountTokens"'))->toBeLessThan(strpos($html, 'x-data="accountData"'));
    });

    it('links to the documentation of the API', function (): void {
        $this->actingAs(User::factory()->speaking('en')->create())->get(route('app.account'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('<a href="/docs/api">API documentation</a>', false);
    });

    it('links to the documentation by its path, never by an address built from the Host header', function (): void {
        $page = $this->actingAs(User::factory()->speaking('en')->create())->get(route('app.account'), ['Accept-Language' => 'en', 'Host' => 'evil.example'])->assertOk();

        expect($page->getContent())->toContain('<a href="/docs/api">API documentation</a>')->not->toContain('evil.example/docs/api');
    });

    it('reads its list again when the security card has revoked every token', function (): void {
        $this->actingAs(User::factory()->create())->get(route('app.account'))
            ->assertOk()
            ->assertSee('@tokens-revoked.window="load"', false);
    });

    it('has the form of a token: a name of 80 characters at most, the three permissions with reading ticked, and the lifetimes', function (): void {
        $page = $this->actingAs(User::factory()->speaking('en')->create())->get(route('app.account'), ['Accept-Language' => 'en'])->assertOk();

        expect($page->getContent())
            ->toMatch('/<input\s[^>]*id="api-token-name"[^>]*maxlength="80"[^>]*required/s')
            ->toMatch('/value="read" x-model="form.abilities"/')
            ->toMatch('/value="write" x-model="form.abilities"/')
            ->toMatch('/value="manage" x-model="form.abilities"/');

        $page->assertSeeInOrder([
            '<option value="7">7 days</option>',
            '<option value="30">30 days</option>',
            '<option value="90">90 days</option>',
            '<option value="365">365 days</option>',
            '<option value="">Never</option>',
        ], false);

        // The permissions are the ones the API takes, and nothing else is offered.
        preg_match_all('/name="abilities" value="(\w+)"/', $page->getContent(), $offered);

        expect($offered[1])->toBe(TokenAbility::values());
    });

    it('hands the script every message it shows, in the language of the page', function (): void {
        $this->actingAs(User::factory()->create());

        $english = clientMessagesOf($this->get(route('app.account'), ['Accept-Language' => 'en'])->assertOk());
        $french = clientMessagesOf($this->get(route('app.account'), ['Accept-Language' => 'fr'])->assertOk());

        $keys = [
            'tokens.ability.read', 'tokens.ability.write', 'tokens.ability.manage',
            'tokens.created', 'tokens.last_used', 'tokens.never_used', 'tokens.expires', 'tokens.never_expires',
            'tokens.revoke_named', 'tokens.confirm_revoke', 'tokens.copy', 'tokens.copied', 'tokens.select_hint',
            'tokens.notice.revoked', 'tokens.error.limit',
        ];

        expect($english)->toHaveKeys($keys)
            ->and($french)->toHaveKeys($keys)
            ->and($english['tokens.confirm_revoke'])->toContain(':name')
            ->and($english['tokens.created'])->toContain(':date')
            ->and($english['tokens.error.limit'])->toContain(':count')
            ->and($french['tokens.ability.read'])->toBe('Lecture')
            ->and($french['tokens.ability.write'])->toBe('Écriture')
            ->and($french['tokens.ability.manage'])->toBe('Administration');
    });

    it('names a permission the way the rest of the application does', function (): void {
        $this->actingAs(User::factory()->create());

        foreach (['en', 'fr'] as $locale) {
            $messages = clientMessagesOf($this->get(route('app.account'), ['Accept-Language' => $locale])->assertOk());

            app()->setLocale($locale);

            foreach (TokenAbility::cases() as $ability) {
                expect($messages["tokens.ability.{$ability->value}"])->toBe($ability->label());
            }
        }
    });

    it('is translated', function (): void {
        $this->actingAs(User::factory()->create())->get(route('app.account'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Jetons d&#039;API', false)
            ->assertSee('Documentation de l&#039;API', false)
            ->assertSee('Créer un jeton')
            ->assertSee('Créer le jeton')
            ->assertSee('Lecture')
            ->assertSee('Écriture')
            ->assertSee('Administration')
            ->assertSee('Révoquer')
            ->assertSee('Expire après')
            ->assertSee('<option value="">Jamais</option>', false)
            ->assertSee('J&#039;ai copié le jeton', false);
    });

    it('is not shown to a visitor', function (): void {
        $this->get(route('app.account'))->assertRedirect(route('login'));
    });
});
