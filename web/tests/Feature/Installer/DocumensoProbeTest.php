<?php

declare(strict_types=1);

use App\Installer\Probes\DocumensoProbe;
use App\Installer\Settings\DocumensoSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('lists one envelope with the bare token in the Authorization header', function (): void {
    Http::fake(['sign.example.test/*' => Http::response(['data' => []], 200)]);

    $result = app(DocumensoProbe::class)->check(new DocumensoSettings('https://sign.example.test', 'api_secret'));

    expect($result->ok)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://sign.example.test/api/v2/envelope?page=1&perPage=1'
        && $request->header('Authorization') === ['api_secret']);
    Http::assertSentCount(1);
});

it('refuses a rejected token and points at the API key', function (int $status): void {
    Http::fake(['*' => Http::response(['message' => 'nope'], $status)]);

    $result = app(DocumensoProbe::class)->check(new DocumensoSettings('https://sign.example.test', 'api_bad'));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('api_key')->and($result->message)->not->toContain('api_bad');
})->with([401, 403]);

it('recognises an address that is not Documenso', function (): void {
    Http::fake(['*' => Http::response('<html>Not found</html>', 404)]);

    expect(app(DocumensoProbe::class)->check(new DocumensoSettings('https://example.test', 'api_x'))->field)->toBe('url');
});

it('does not follow redirects, which would carry the token elsewhere', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test'])]);

    $result = app(DocumensoProbe::class)->check(new DocumensoSettings('https://example.test', 'api_x'));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('url')->and($result->message)->toContain('redirected');
    Http::assertSentCount(1);
});

it('reports a server error with its status', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    $result = app(DocumensoProbe::class)->check(new DocumensoSettings('https://example.test', 'api_x'));

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('503');
});

it('reports an unreachable instance', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7'));

    $result = app(DocumensoProbe::class)->check(new DocumensoSettings('https://example.test', 'api_x'));

    expect($result->ok)->toBeFalse()->and($result->field)->toBe('url')->and($result->message)->toContain('Cannot reach');
});

it('accepts the instance address in several shapes', function (string $url): void {
    expect((new DocumensoSettings($url, 'k'))->apiBaseUrl())->toBe('https://sign.example.test/documenso/api/v2');
})->with([
    'plain' => 'https://sign.example.test/documenso',
    'trailing slash' => 'https://sign.example.test/documenso/',
    'api address' => 'https://sign.example.test/documenso/api/v2',
    'api address with slash' => 'https://sign.example.test/documenso/api/v2/',
    'padded' => '  https://sign.example.test/documenso ',
]);
