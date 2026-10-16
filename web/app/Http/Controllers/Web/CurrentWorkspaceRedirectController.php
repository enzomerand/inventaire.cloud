<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\PublicUrls;
use App\Workspaces\Workspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/app`: sends the signed-in user to the page of the workspace they are working in (see {@see WorkspacePageController}).
 *
 * The workspace is the one they chose, else the first one they joined; an account that somehow has none is given its
 * personal workspace on the way ({@see Workspaces::current()}). If it still has none (the account was deleted while
 * the request ran), the person is sent to the list of their workspaces, which renders without one: never to the login
 * page, which sends a signed-in visitor straight back here. Somebody who comes from the login page, where a page that needs
 * an account had sent them, goes back to that page instead, if it is a page of the account: an address of this instance
 * (the one of `APP_URL`) under `/app/`. Whatever else the session remembered as the place they were going (another site, a
 * public page, the login page, this very address, which would loop) is forgotten, and they land in their workspace: the
 * address is kept from a request that came before and says nothing about what the person wants now.
 */
final class CurrentWorkspaceRedirectController extends Controller
{
    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Redirects to the page of the current workspace, or to the list of workspaces when there is none.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $workspace = $this->workspaces->current($this->user($request));

        if ($workspace === null) {
            return redirect()->route('app.workspaces');
        }

        // A visitor who was sent to the login page by a page of /app (the "use this template" button, say) is taken back to
        // it: the framework remembered where they were going. The memory is used once, whatever it holds; anybody else, and
        // whoever it holds a place that is not a page of the account for, lands in their workspace.
        $intended = $request->session()->pull('url.intended');

        return redirect()->to($this->pageOfTheAccount($intended) ?? route('app.workspace', ['workspace' => $workspace->ulid]));
    }

    /**
     * The address of the page when what the session remembered is a page of the account: on this instance (the host and the
     * port of `APP_URL`) and under `/app/`. Built from `APP_URL` and the path and query that were remembered, never taken as
     * it was written. Null for anything else.
     */
    private function pageOfTheAccount(mixed $intended): ?string
    {
        if (! is_string($intended) || preg_match('/[\x00-\x20\x7f\\\\]/', $intended) === 1) {
            return null;
        }

        $address = parse_url($intended);
        $instance = parse_url(PublicUrls::root());

        if ($address === false || $instance === false || ! isset($address['scheme'], $address['host'], $address['path'], $instance['scheme'], $instance['host'])) {
            return null;
        }

        if (isset($address['user']) || isset($address['pass']) || ! $this->sameOrigin($address, $instance)) {
            return null;
        }

        $path = $address['path'];

        // Under /app/, and no way out of it: the browser would resolve the dot segments (written or percent-encoded).
        $decoded = rawurldecode($path);

        if (! str_starts_with($path, '/app/') || $path === '/app/' || preg_match('#(?:^|/)\.\.?(?:/|$)#', $decoded) === 1 || str_contains($decoded, '//')) {
            return null;
        }

        return PublicUrls::to($path.(isset($address['query']) && $address['query'] !== '' ? '?'.$address['query'] : ''));
    }

    /**
     * Whether two parsed addresses are the same origin: scheme, host and port (the default one of the scheme when none is written).
     *
     * @param  array<string, int|string>  $first
     * @param  array<string, int|string>  $second
     */
    private function sameOrigin(array $first, array $second): bool
    {
        $port = fn (array $address): int => (int) ($address['port'] ?? (strtolower((string) $address['scheme']) === 'https' ? 443 : 80));

        return strtolower((string) $first['scheme']) === strtolower((string) $second['scheme'])
            && strtolower((string) $first['host']) === strtolower((string) $second['host'])
            && $port($first) === $port($second);
    }
}
