<?php

declare(strict_types=1);

namespace App\Pwa;

use RuntimeException;

/**
 * The service worker the browser downloads from `/sw.js`.
 *
 * The worker itself is a plain script (`resources/js/sw/service-worker.js`) that Vite does not build: it has to be served from the root of
 * the site to control every page (a script under `/build/` could only control that folder), and it reads what depends on the instance
 * from `self.INVENTAIRE_SW`, a line this class writes in front of it: the version, and the address of the page it shows when the
 * network is down.
 *
 * The version is what makes browsers replace the worker. A browser checks `/sw.js` each time the application is opened, and installs
 * a new worker as soon as one byte differs: the version is a hash of everything the worker's behaviour is made of (its source, the offline
 * page it keeps, and what that page shows: the name of the instance, the translations of its sentences and its colors), so a deployment
 * that changes any of them changes the worker, and the one that did not change any of them leaves the installed worker alone.
 */
final class ServiceWorkerScript
{
    /**
     * The address of the page shown when the network is down.
     */
    public const OFFLINE_PATH = '/offline';

    /**
     * The file of the worker, relative to `resources/`.
     */
    private const WORKER = 'js/sw/service-worker.js';

    /**
     * The view of the offline page, relative to `resources/`: its source is part of the version, since the worker keeps a copy of it.
     */
    private const OFFLINE_VIEW = 'views/pwa/offline.blade.php';

    /**
     * The hash that names this state of the worker: the first 16 hexadecimal digits of the SHA-256 of what the worker is made of.
     */
    public function version(): string
    {
        return substr(hash('sha256', implode("\0", [
            $this->read(resource_path(self::WORKER)),
            $this->read(resource_path(self::OFFLINE_VIEW)),
            $this->translations(),
            WebManifest::THEME_COLOR,
            WebManifest::BACKGROUND_COLOR,
            config()->string('app.name'),
        ])), 0, 16);
    }

    /**
     * The whole script: the configuration line, then the source of the worker.
     */
    public function body(): string
    {
        $configuration = json_encode([
            'version' => $this->version(),
            'offlineUrl' => self::OFFLINE_PATH,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'self.INVENTAIRE_SW = '.$configuration.";\n".$this->read(resource_path(self::WORKER));
    }

    /**
     * The translation files (`lang/*.json`, one per language) with their names, in a fixed order: the offline page is shown in the
     * language of the request, with the sentences of these files, and the copy the worker keeps is the one of the version it was made in
     * (a new language is part of the version too, as a file that is added).
     *
     * @throws RuntimeException When a file cannot be read.
     */
    private function translations(): string
    {
        $files = glob(lang_path('*.json')) ?: [];

        sort($files);

        return implode("\0", array_map(fn (string $file): string => basename($file)."\0".$this->read($file), $files));
    }

    /**
     * The content of a file, given by its absolute path.
     *
     * @throws RuntimeException When the file cannot be read: a worker that is not the real one must never be served.
     */
    private function read(string $path): string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Cannot read '.basename($path).'.');
        }

        return $content;
    }
}
