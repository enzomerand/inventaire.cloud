<?php

declare(strict_types=1);

// Helpers shared by the tests of share links. Each test file loads them with require_once, so a file can be run alone. Their names all
// start with "shareLink" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Sharing\ShareLinks;
use App\Models\Inventory;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/inventories.php';
require_once __DIR__.'/tokens.php';

/**
 * A workspace with one person in each role and an inventory in it.
 *
 * @return array{team: array{owner: User, editor: User, viewer: User, stranger: User, workspace: Workspace}, owner: User, inventory: Inventory}
 */
function shareLinkCase(): array
{
    $team = inventoryTeam();

    return ['team' => $team, 'owner' => $team['owner'], 'inventory' => inventoryIn($team['workspace'], 'Tools')];
}

/**
 * The address of the share links of an inventory, or of one of them.
 */
function shareLinkUri(Inventory $inventory, ?ShareLink $link = null): string
{
    return "/api/v1/inventories/{$inventory->ulid}/share-links".($link === null ? '' : '/'.$link->ulid);
}

/**
 * A link made the way the application makes one (the service), with its token.
 *
 * @return array{link: ShareLink, token: string}
 */
function shareLinkMake(Inventory $inventory, ?User $by = null, ?string $label = null, bool $includeFiles = true, ?int $days = 30): array
{
    return app(ShareLinks::class)->create($by ?? inventoryPerson('Author'), $inventory, $label, $includeFiles, $days);
}

/**
 * A call to the share links by the web front (a session).
 *
 * @param  array<string, mixed>  $data
 */
function shareLinkCall(object $test, ?User $as, string $method, string $uri, array $data = []): TestResponse
{
    return inventoryCall($test, $as, $method, $uri, $data);
}

/**
 * A call to the share links by a script with a token of the given abilities.
 *
 * @param  list<string>  $abilities
 * @param  array<string, mixed>  $data
 */
function shareLinkTokenCall(object $test, User $as, array $abilities, string $method, string $uri, array $data = []): TestResponse
{
    return tokenCall($test, tokenFor($as, $abilities), $method, $uri, $data);
}
