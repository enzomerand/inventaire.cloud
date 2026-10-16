<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\SetRobotsTag;
use App\Support\PublicUrls;
use Illuminate\Http\Response;

/**
 * `/robots.txt`: what robots may fetch, according to `app.indexable` (`APP_INDEXABLE`).
 *
 * It is a route, not a file of `public/`: a web server serves an existing file before the application, and the answer
 * depends on the configuration of each instance. Indexing off, everything is disallowed. On, the public pages are allowed,
 * the application itself is not (the paths under `/app/` and `/api/`, and `/install` and `/login`), and the sitemap is
 * announced.
 *
 * A rule is the start of a path, so `/app` would also close `/apple-pie`, a path a page of the instance may have one day:
 * the folders of the application end with a slash. `/install` and `/login` are the paths of two pages (the first has
 * paths under it, which the rule covers). Robots that do not obey this file, and the engines that find a page by a link
 * anyway, are told by the `X-Robots-Tag` header and the `<meta>` of the page ({@see SetRobotsTag}).
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
                'Disallow: /app/',
                'Disallow: /api/',
                'Disallow: /install',
                'Disallow: /login',
                '',
                'Sitemap: '.PublicUrls::to('/sitemap.xml'),
            ]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
