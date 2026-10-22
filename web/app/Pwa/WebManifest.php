<?php

declare(strict_types=1);

namespace App\Pwa;

/**
 * The web app manifest of the instance (`/manifest.webmanifest`): what a browser reads to offer to install the application, and
 * to open it from the home screen with a name, an icon and no browser bars.
 *
 * The name is the one of the instance (`APP_NAME`), the description and the language follow the language of the request. Every path is
 * absolute from the root of the site and carries no host: the manifest then stays right behind any reverse proxy, and the worker
 * ({@see ServiceWorkerScript}) is registered at `/`, the scope the manifest announces. The icons are the static files of
 * `public/icons`.
 */
final class WebManifest
{
    /**
     * The color of the bars of the browser around the application (the blue of the interface), also the one of the icons.
     */
    public const THEME_COLOR = '#066fd1';

    /**
     * The color shown while the installed application starts, until its first page is drawn.
     */
    public const BACKGROUND_COLOR = '#fafafa';

    /**
     * Where the installed application opens, and what identifies it: the space of the signed-in person (the login page when nobody is).
     */
    public const START_URL = '/app';

    /**
     * The manifest as a list, ready to be written as JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $name = config()->string('app.name');

        return [
            'id' => self::START_URL,
            'name' => $name,
            'short_name' => $name,
            'description' => __('Free inventory tool for professionals and individuals.'),
            'lang' => str_replace('_', '-', app()->getLocale()),
            'dir' => 'ltr',
            'start_url' => self::START_URL,
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => self::THEME_COLOR,
            'background_color' => self::BACKGROUND_COLOR,
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }

    /**
     * The manifest written as JSON (slashes and accents as they are: the file is read by browsers, and by people).
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
    }
}
