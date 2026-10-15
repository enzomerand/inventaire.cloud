<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * One browser of a person, for tests that need several of them at once.
 *
 * The test client does not keep cookies between requests, and its session store lives in the process, so two
 * "devices" would share what the first one put in the session. A device keeps its own cookies (the way a browser
 * does: it stores what the answers set and sends them back, forgetting a cookie the answer expires) and empties
 * the process's session store before each request, as a new PHP process would see it. Sessions themselves stay in
 * the session handler (the array one, or the database), keyed by the id each device holds.
 *
 * Requests are those of the web front: see `frontend()` in tests/Pest.php.
 */
final class Device
{
    /** @var array<string, string> Cookie values as the answers sent them (still encrypted). */
    private array $cookies = [];

    public function __construct(private readonly object $test) {}

    /**
     * Signs in with an address that has an account, through the real code flow, and returns the answer.
     */
    public function signIn(string $email): TestResponse
    {
        $this->request('POST', '/api/v1/auth/code', ['email' => $email])->assertOk();

        return $this->request('POST', '/api/v1/auth/verify', ['email' => $email, 'code' => lastSentCode()]);
    }

    /**
     * Sends a request the way this device's browser would.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->prepare();

        return $this->remember($this->test->json($method, $uri, $data, frontend($headers)));
    }

    /**
     * GETs a page (HTML) the way this device's browser would.
     */
    public function visit(string $uri): TestResponse
    {
        $this->prepare();

        return $this->remember($this->test->get($uri));
    }

    /**
     * The value of a cookie this device holds (encrypted, as the browser stores it), or null.
     */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * The id of the session this device holds, or null: what the session handler keys it by.
     */
    public function sessionId(): ?string
    {
        $cookie = $this->cookie(config()->string('session.cookie'));

        return $cookie === null ? null : CookieValuePrefix::remove(app('encrypter')->decrypt($cookie, false));
    }

    /**
     * The name of the "remember me" cookie this device holds, or null.
     */
    public function rememberCookieName(): ?string
    {
        foreach (array_keys($this->cookies) as $name) {
            if (str_starts_with($name, 'remember_web_')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A second browser that holds the same cookies as this one (a copy of the profile, or a stolen cookie).
     */
    public function copy(): self
    {
        $copy = new self($this->test);
        $copy->cookies = $this->cookies;

        return $copy;
    }

    /**
     * A browser that kept the "remember me" cookie of this one but lost its session (it expired, or was closed).
     */
    public function withoutSession(): self
    {
        $copy = $this->copy();
        unset($copy->cookies[config()->string('session.cookie')]);

        return $copy;
    }

    /**
     * Forgets what the test client kept of the previous call, which a second browser must not see, and loads the
     * cookies of this device.
     */
    private function prepare(): void
    {
        $cookies = $this->cookies;

        (function () use ($cookies): void {
            $this->flushSession();
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            // An API call makes "sanctum" the default guard for the rest of the process; a request of its own starts with "web".
            $this->app['auth']->shouldUse('web');
            // Cookies queued by a previous call would otherwise be sent with the answer to this one.
            $this->app['cookie']->flushQueuedCookies();
            $this->defaultCookies = [];
            $this->unencryptedCookies = [];
            $this->withCredentials()->withUnencryptedCookies($cookies);
        })->call($this->test);
    }

    /**
     * Keeps the cookies an answer sets.
     */
    private function remember(TestResponse $response): TestResponse
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->store($cookie);
        }

        return $response;
    }

    /**
     * Applies a cookie an answer set: stores it, or forgets it when the answer expired it.
     */
    private function store(Cookie $cookie): void
    {
        if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
            unset($this->cookies[$cookie->getName()]);

            return;
        }

        $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
    }
}
