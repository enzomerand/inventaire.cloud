<?php

declare(strict_types=1);

// Helpers shared by the tests of share links. Each test file loads them with require_once, so a file can be run alone. Their names all
// start with "shareLink" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Inventories\Sharing\ShareLinks;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/inventories.php';
require_once __DIR__.'/tokens.php';
require_once __DIR__.'/exports.php';

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

/**
 * The inventory of the export tests (a field of every type, three items) with a link that shows it. The inventory is called "Office
 * equipment" and described with a bit of markup, which the page must show as text.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, fields: array<string, InventoryField>, items: array<string, Item>, choices: array<string, string>, link: ShareLink, token: string}
 */
function shareFixture(bool $files = true, ?int $days = 30): array
{
    $case = exportFixture('Office equipment');
    $case['inventory']->update(['description' => 'Everything <b>we</b> own']);

    return $case + shareLinkMake($case['inventory'], $case['team']['owner'], 'Insurer', $files, $days);
}

/**
 * The public page of a link, read by a visitor: no session, no sign-in.
 *
 * @param  array<string, string>  $headers
 */
function sharePageGet(object $test, string $token, string $query = '', array $headers = []): TestResponse
{
    return $test->get('/s/'.$token.$query, $headers);
}

/**
 * The page of a response, ready to be queried with XPath.
 */
function shareDom(TestResponse $response): DOMXPath
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    libxml_clear_errors();

    return new DOMXPath($dom);
}

/**
 * The texts of the nodes an XPath query finds, with the spaces (the non-breaking ones too) squeezed.
 *
 * @return list<string>
 */
function shareTexts(DOMXPath $page, string $query): array
{
    $texts = [];

    foreach ($page->query($query) ?: [] as $node) {
        $texts[] = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $node->textContent));
    }

    return $texts;
}

/**
 * The values of an attribute of the nodes an XPath query finds.
 *
 * @return list<string>
 */
function shareAttributes(DOMXPath $page, string $query, string $attribute): array
{
    $values = [];

    foreach ($page->query($query) ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $values[] = $node->getAttribute($attribute);
        }
    }

    return $values;
}
