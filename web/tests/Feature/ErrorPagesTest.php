<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * The pages of the errors (resources/views/errors): the framework's own views extend ours, so every status gets the language of the
 * visitor, the name of the application, a way home, and nothing that needs the session or the build of the front.
 */

/**
 * Asks for an address that answers with the given status, whatever made it: a page that is not there, or a route that refuses.
 *
 * @param  array<string, string>  $headers
 */
function errorPageGet(object $test, int $status, array $headers = []): TestResponse
{
    Route::get('/error-page-test/{status}', fn (int $status) => abort($status))->whereNumber('status');

    return $test->get($status === 404 ? '/no-such-page' : '/error-page-test/'.$status, $headers);
}

describe('the language', function (): void {
    it('is the one of the visitor on the page of an error', function (int $status, string $header, string $language, string $title): void {
        $response = errorPageGet($this, $status, ['Accept-Language' => $header])->assertStatus($status);

        $response->assertSee('<html lang="'.$language.'"', false)
            ->assertSee('<title>'.$title.' · '.config('app.name').'</title>', false)
            ->assertHeader('Content-Language', $language);

        // The framework's own page says "en" whatever the visitor reads.
        if ($language === 'fr') {
            $response->assertDontSee('lang="en"', false);
        }
    })->with([
        'a page that is not there, in French' => [404, 'fr-FR,fr;q=0.9', 'fr', 'Non trouvé'],
        'a page that is not there, in English' => [404, 'en-US,en;q=0.9', 'en', 'Not Found'],
        'a refusal, in French' => [403, 'fr', 'fr', 'Interdit'],
        'a refusal, in English' => [403, 'en', 'en', 'Forbidden'],
        'an expired page, in French' => [419, 'fr', 'fr', 'Page expirée'],
    ]);

    it('is the one of the cookie when the visitor chose', function (): void {
        $this->withUnencryptedCookie('locale', 'en')
            ->get('/no-such-page', ['Accept-Language' => 'fr'])
            ->assertNotFound()
            ->assertSee('<html lang="en"', false);
    });
});

describe('the page', function (): void {
    it('explains a 404 and leads back to the home page, in both languages', function (string $header, string $hint, string $back): void {
        $response = $this->get('/no-such-page', ['Accept-Language' => $header])->assertNotFound();

        $response->assertSee($hint)
            ->assertSee('<a href="/">'.e($back).'</a>', false);
    })->with([
        'French' => ['fr', "Cette page n'existe pas ou n'est plus disponible. Si quelqu'un vous a envoyé ce lien, il a peut-être expiré.", "Retour à l'accueil"],
        'English' => ['en', 'This page does not exist or is no longer available. If someone sent you this link, it may have expired.', 'Back to the home page'],
    ]);

    it('leads back to the home page on a refusal too, without the hint of the 404', function (): void {
        $response = errorPageGet($this, 403, ['Accept-Language' => 'en'])->assertForbidden();

        $response->assertSee('<a href="/">Back to the home page</a>', false)
            ->assertDontSee('no longer available');
    });

    it('stands alone: no session, no token, no script and no file of the build', function (int $status): void {
        $response = errorPageGet($this, $status)->assertStatus($status);
        $content = $response->getContent();

        expect($response->headers->getCookies())->toBe([])
            ->and($content)->not->toContain('csrf')
            ->and($content)->not->toContain('<script')
            ->and($content)->not->toContain('/build/')
            ->and($content)->not->toContain('<link rel="stylesheet"');
    })->with([404, 403, 419]);
});
