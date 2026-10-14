<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\UpdateLocaleController;
use Illuminate\Contracts\Cookie\Factory as CookieFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Decides which language a request is answered in.
 *
 * Sources are tried in a fixed order and the first one that yields a supported
 * locale wins:
 *
 *  1. the long-lived `locale` cookie written by the language switcher,
 *  2. the best match in the browser's Accept-Language header,
 *  3. the application default.
 *
 * An explicit choice (the cookie) must beat the browser's header, otherwise the
 * switcher would seem to do nothing for people whose browser speaks another
 * language than the one they want here.
 *
 * The language of an account (`users.locale`) is not a source of its own. This
 * resolver runs in a global middleware, before the session and authentication
 * middleware, so the signed-in user is not known yet, and starting the session
 * earlier just to read it would cost every request (404s, errors, the install
 * wizard) a session and a database query. The cookie carries the account's
 * language instead: signing in sets the cookie from `users.locale`, and the
 * language switcher updates the cookie and `users.locale` together (see
 * {@see UpdateLocaleController}). The account therefore
 * follows its owner from one device to the next at sign-in, while each device
 * keeps its cookie in between.
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
        // Priority order; each source is only consulted if the previous ones found nothing usable.
        $sources = [
            $this->fromCookie(...),
            $this->fromAcceptLanguage(...),
        ];

        foreach ($sources as $source) {
            $locale = $source($request);

            if ($locale !== null && $this->isSupported($locale)) {
                return $locale;
            }
        }

        return config()->string('app.locale');
    }

    /**
     * The locale the current request is answered in (set by the SetLocale
     * middleware). Always a supported locale.
     */
    public function resolved(): string
    {
        $locale = app()->getLocale();

        return $this->isSupported($locale) ? $locale : config()->string('app.locale');
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
     * Locales the application is translated into: the keys of
     * `app.locale_names`, the one place where they are declared.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        return array_map(strval(...), array_keys(config()->array('app.locale_names')));
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
