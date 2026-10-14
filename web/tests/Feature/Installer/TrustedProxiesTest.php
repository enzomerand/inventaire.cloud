<?php

declare(strict_types=1);

use App\Services\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/whoami', fn (Request $request): string => (string) $request->ip());
});

afterEach(function (): void {
    TrustProxies::flushState();
});

/**
 * The address the application sees for a request relayed by a proxy.
 */
function seenThroughProxy(object $test, string $proxy, string $client): string
{
    return $test->withServerVariables(['REMOTE_ADDR' => $proxy])->get('/whoami', ['X-Forwarded-For' => $client])->getContent();
}

it('sees the proxy as the client by default, so a forged header is worthless', function (): void {
    TrustedProxies::apply(config('app.trusted_proxies'));

    expect(seenThroughProxy($this, '10.0.0.5', '203.0.113.7'))->toBe('10.0.0.5');
});

it('sees the real client behind a proxy that is trusted', function (): void {
    TrustedProxies::apply(TrustedProxies::parse('10.0.0.0/8'));

    expect(seenThroughProxy($this, '10.0.0.5', '203.0.113.7'))->toBe('203.0.113.7');
});

it('does not believe a proxy that is not on the list', function (): void {
    TrustedProxies::apply(TrustedProxies::parse('10.0.0.0/8'));

    expect(seenThroughProxy($this, '198.51.100.9', '203.0.113.7'))->toBe('198.51.100.9');
});

it('is configured from TRUSTED_PROXIES and wired when the application boots', function (): void {
    expect(config('app.trusted_proxies'))->toBe([])
        ->and(file_get_contents(base_path('bootstrap/app.php')))->toContain('TrustedProxies::apply')
        ->and(file_get_contents(base_path('config/app.php')))->toContain("env('TRUSTED_PROXIES'");
});

it('keys the setup token throttle on the real client address behind a trusted proxy', function (): void {
    $this->bootInstaller(installed: false);
    TrustedProxies::apply(TrustedProxies::parse('10.0.0.5'));

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->postJson('/install/token', ['token' => 'wrong'], ['X-Forwarded-For' => '203.0.113.7'])->assertUnprocessable();
    }

    // Same proxy, another visitor: not throttled with the first one.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->postJson('/install/token', ['token' => 'wrong'], ['X-Forwarded-For' => '203.0.113.99'])->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->postJson('/install/token', ['token' => 'wrong'], ['X-Forwarded-For' => '203.0.113.7'])->assertStatus(429);
});
