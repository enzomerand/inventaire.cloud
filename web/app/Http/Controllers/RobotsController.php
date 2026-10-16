<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PublicUrls;
use Illuminate\Http\Response;

/**
 * `/robots.txt`: what robots may fetch, according to `app.indexable` (`APP_INDEXABLE`).
 *
 * It is a route, not a file of `public/`: a web server serves an existing file before the application, and the answer
 * depends on the configuration of each instance. Indexing off, everything is disallowed. On, the public pages are allowed,
 * the application itself is not (`/app`, `/api`, `/install`, `/login`), and the sitemap is announced.
 */
final class RobotsController extends Controller
{
    /**
     * The robots.txt of the instance, as plain text.
     */
    public function __invoke(): Response
    {
        $lines = config()->boolean('app.indexable')
            ? [
                'User-agent: *',
                'Allow: /',
                'Disallow: /app',
                'Disallow: /api',
                'Disallow: /install',
                'Disallow: /login',
                '',
                'Sitemap: '.PublicUrls::to('/sitemap.xml'),
            ]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
