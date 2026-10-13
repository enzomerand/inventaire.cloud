<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cookie\Factory as CookieFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Decides which language a request is answered in.
 *
 * Sources are tried in a fixed order and the first one that yields a supported
 * locale wins:
 *
 *  1. the signed-in user's saved preference (see {@see self::fromUserPreference()}),
 *  2. the long-lived `locale` cookie written by the language switcher,
 *  3. the best match in the browser's Accept-Language header,
 *  4. the application default.
 *
 * An explicit choice (user preference, cookie) must beat the browser's header,
 * otherwise the switcher would seem to do nothing for people whose browser
 * speaks another language than the one they want here.
 */
final class LocaleResolver
{
    /**
     * Name of the cookie remembering the visitor's choice.
     *
     * It is excluded from encryption (see bootstrap/app.php): the value is
     * validated against the supported list on every read, so nothing sensitive
     * or trusting is stored, and an unencrypted cookie can be read before the
     * web middleware group runs, which the global SetLocale middleware needs.
     */
    public const COOKIE_NAME = 'locale';

    /**
     * Lifetime of the cookie: one year, in minutes.
     */
    public const COOKIE_LIFETIME_MINUTES = 60 * 24 * 365;

    public function __construct(private readonly CookieFactory $cookies) {}

    /**
     * Returns the locale to answer the request in. Always a supported locale.
     */
    public function resolve(Request $request): string
    {
        foreach ($this->sources() as $source) {
            $locale = $source($request);

            if ($locale !== null && $this->isSupported($locale)) {
                return $locale;
            }
        }

        return config()->string('app.locale');
    }

    /**
     * Builds the cookie that remembers a locale for a year.
     *
     * Path, domain, Secure and SameSite follow the session cookie settings;
     * HttpOnly is on because scripts never need to read it.
     */
    public function cookie(string $locale): Cookie
    {
        return $this->cookies->make(self::COOKIE_NAME, $locale, self::COOKIE_LIFETIME_MINUTES);
    }

    /**
     * Whether the application ships translations for this locale.
     */
    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported(), true);
    }

    /**
     * Locales the application is translated into.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        return array_values(array_map(strval(...), config()->array('app.supported_locales')));
    }

    /**
     * The sources in priority order. Each returns a locale code or null; the
     * result is validated by the caller, so sources can stay naive.
     *
     * @return list<callable(Request): ?string>
     */
    private function sources(): array
    {
        return [
            $this->fromUserPreference(...),
            $this->fromCookie(...),
            $this->fromAcceptLanguage(...),
        ];
    }

    /**
     * Hook for the authenticated user's saved language. Always null until
     * accounts exist (lot 3), which widens the return type to ?string and reads
     * the preference from the user.
     *
     * Note for that lot: this resolver runs in a global middleware, before the
     * session and authentication middleware of the web group. Resolve the user
     * from the guard directly (token or session), or apply the preference again
     * once the user is known, instead of relying on `$request->user()` here.
     */
    private function fromUserPreference(Request $request): null
    {
        return null;
    }

    /**
     * Reads the locale remembered by the language switcher.
     */
    private function fromCookie(Request $request): ?string
    {
        $value = $request->cookies->get(self::COOKIE_NAME);

        return is_string($value) ? $value : null;
    }

    /**
     * Finds the first language the browser asks for that the application supports.
     *
     * Symfony already orders the header by quality value, so the first hit is the
     * visitor's best option. A regional tag ("fr-CH") matches its base language
     * ("fr") when the region itself is not supported.
     */
    private function fromAcceptLanguage(Request $request): ?string
    {
        foreach ($request->getLanguages() as $candidate) {
            if ($this->isSupported($candidate)) {
                return $candidate;
            }

            $language = strtolower(explode('_', $candidate)[0]);

            if ($this->isSupported($language)) {
                return $language;
            }
        }

        return null;
    }
}
