<?php

declare(strict_types=1);

namespace App\Http\Controllers\Share;

use App\Http\Controllers\Controller;
use App\Inventories\Exports\ExportFormatter;
use App\Inventories\Sharing\SharedInventory;
use App\Inventories\Sharing\ShareLinks;
use App\Support\DisplayTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/s/{token}`: the public page of a share link, the inventory read-only for anybody who holds the address.
 *
 * It is a page of the server, with nothing to run in the browser, and it keeps no session: the route runs without the session, the
 * cookies and the CSRF check (a visitor has none of them, and a session would store the address, which is the secret). It shows the name
 * and the description of the inventory, its fields as columns and its items, 50 a page (`?page=`), narrowed by the words of `?q=`; no sort,
 * no filter, no total, no history, and nothing about the workspace or its members (see {@see SharedInventory}).
 *
 * Every reason the address does not open anything (not shaped like a token, unknown, revoked, expired, sharing turned off by the
 * administrator) is the same `404` page, so that a visitor learns nothing about which links exist ({@see ShareLinks::resolve()}). The page is
 * for robots to leave alone (`noindex, nofollow`) and for nobody to keep (`Cache-Control: private, no-store`): a revoked link must not
 * linger in a cache. Opening it notes the use of the link, at most once an hour ({@see ShareLinks::touch()}).
 */
final class SharePageController extends Controller
{
    /**
     * Gets the service that finds the link and the one that draws its inventory.
     */
    public function __construct(
        private readonly ShareLinks $links,
        private readonly SharedInventory $shared,
    ) {}

    /**
     * Shows a page of the inventory of the link.
     */
    public function __invoke(Request $request, string $token): Response
    {
        $link = $this->links->resolve($token);

        abort_if($link === null, 404);

        $this->links->touch($link);

        $search = $request->query('q');
        $page = $request->query('page');

        $shared = $this->shared->page(
            $link,
            $token,
            is_string($search) ? $search : '',
            is_string($page) && ctype_digit($page) ? (int) min((int) $page, SharedInventory::MAX_PAGE) : 1,
        );

        return response()
            ->view('share.show', [
                'inventory' => $link->inventory,
                'token' => $token,
                'shared' => $shared,
                'expires' => $link->expires_at === null ? null : (new ExportFormatter(app()->getLocale(), DisplayTime::zone()))->moment($link->expires_at->toDateTimeImmutable()),
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
