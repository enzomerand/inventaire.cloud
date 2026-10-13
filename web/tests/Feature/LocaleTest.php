<?php

declare(strict_types=1);

use App\View\Components\ClientMessages;

/**
 * Asserts the rendered page declares the given language on <html>.
 */
function assertHtmlLang(Illuminate\Testing\TestResponse $response, string $locale): void
{
    $response->assertSee('<html lang="'.$locale.'"', false);
}

describe('locale detection', function (): void {
    it('uses the default locale when the browser sends no preference', function (): void {
        // The test client sends "en-us,en;q=0.5" by default (Symfony), so blank it explicitly.
        assertHtmlLang($this->get(route('home'), ['Accept-Language' => '']), 'fr');
    });

    it('picks the best Accept-Language match among the supported locales', function (string $header, string $expected): void {
        assertHtmlLang($this->get(route('home'), ['Accept-Language' => $header]), $expected);
    })->with([
        'exact english' => ['en', 'en'],
        'regional english' => ['en-US,en;q=0.9', 'en'],
        'regional french' => ['fr-CH,fr;q=0.9,en;q=0.8', 'fr'],
        'quality values win over order' => ['en;q=0.4,fr;q=0.9', 'fr'],
        'skips unsupported languages' => ['de-DE,de;q=0.9,en;q=0.8', 'en'],
    ]);

    it('falls back to the default locale when nothing matches', function (): void {
        assertHtmlLang($this->get(route('home'), ['Accept-Language' => 'de-DE,ja;q=0.8,*;q=0.1']), 'fr');
    });

    it('lets an explicit session choice win over the browser', function (): void {
        $response = $this->withSession(['locale' => 'en'])
            ->get(route('home'), ['Accept-Language' => 'fr-FR,fr;q=0.9']);

        assertHtmlLang($response, 'en');
    });

    it('ignores a session value that is no longer supported', function (): void {
        $response = $this->withSession(['locale' => 'xx'])
            ->get(route('home'), ['Accept-Language' => 'en']);

        assertHtmlLang($response, 'en');
    });

    it('announces the language in the Content-Language header', function (): void {
        $this->get(route('home'), ['Accept-Language' => 'en'])->assertHeader('Content-Language', 'en');
    });

    it('translates the interface according to the locale', function (): void {
        $this->get(route('home'), ['Accept-Language' => 'en'])->assertSee('Inventories, simple and free');
        $this->get(route('home'), ['Accept-Language' => 'fr'])->assertSee('Des inventaires simples et gratuits');
    });
});

describe('PUT /locale', function (): void {
    it('stores the chosen locale in the session', function (): void {
        $this->putJson(route('locale.update'), ['locale' => 'en'])
            ->assertNoContent()
            ->assertSessionHas('locale', 'en');
    });

    it('rejects a locale that is not supported', function (string $locale): void {
        $this->putJson(route('locale.update'), ['locale' => $locale])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');

        $this->assertNull(session('locale'));
    })->with(['unknown code' => 'xx', 'region not listed' => 'en_GB', 'injection attempt' => '../fr']);

    it('rejects a missing locale', function (): void {
        $this->putJson(route('locale.update'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    });

    it('reports the validation error in the visitor language', function (): void {
        $this->putJson(route('locale.update'), ['locale' => 'xx'], ['Accept-Language' => 'fr'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.locale.0', fn (string $message): bool => str_contains($message, 'invalide'));
    });
});

describe('locale configuration', function (): void {
    it('names every supported locale and ships a translation file for the non-source ones', function (): void {
        foreach (config()->array('app.supported_locales') as $locale) {
            expect(config()->array('app.locale_names'))->toHaveKey($locale);

            if ($locale !== 'en') {
                expect(lang_path($locale.'.json'))->toBeFile();
            }
        }
    });

    it('uses a supported default and fallback locale', function (): void {
        expect(config()->array('app.supported_locales'))
            ->toContain(config()->string('app.locale'))
            ->toContain(config()->string('app.fallback_locale'));
    });

    it('translates every message handed to the scripts', function (): void {
        $messages = new ClientMessages;

        app()->setLocale('en');
        $english = $messages->messages();

        app()->setLocale('fr');
        $french = $messages->messages();

        foreach ($english as $key => $text) {
            expect($french[$key])->not->toBe($text, "Missing French translation for \"{$text}\"");
        }
    });
});
