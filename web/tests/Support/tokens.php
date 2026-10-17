<?php

declare(strict_types=1);

// Helpers shared by the tests of API tokens. Each test file loads them with require_once, so a file can be run alone. Their
// names all start with "token" or "apiToken" so that they never collide with the helpers of tests/Pest.php or of other test files.

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;

/**
 * Every route of the API behind a token ability, with the ability its token needs. The routes are classified in
 * routes/api.php; this is the same list, written out, so that moving a route to another ability is a decision a test notices.
 *
 * @return array<string, string> Route name => ability.
 */
function tokenRouteClassification(): array
{
    $read = [
        'me', 'field-types.index', 'workspaces.index', 'workspaces.inventories.index', 'inventories.show', 'inventories.items.index',
        'inventories.items.show', 'inventories.items.revisions.index', 'inventories.items.attachments.show',
        'inventories.items.attachments.thumbnail', 'inventories.exports.index', 'inventories.exports.show', 'inventories.exports.download',
        'inventories.imports.index', 'inventories.imports.show',
    ];
    $write = [
        'inventories.items.store', 'inventories.items.update', 'inventories.items.destroy', 'inventories.items.attachments.store',
        'inventories.items.attachments.destroy', 'inventories.exports.store', 'inventories.exports.destroy', 'inventories.imports.store',
        'inventories.imports.update', 'inventories.imports.start', 'inventories.imports.destroy',
    ];
    $manage = [
        'workspaces.store', 'workspaces.update', 'workspaces.destroy', 'workspaces.inventories.store', 'inventories.update',
        'inventories.destroy', 'inventories.fields.store', 'inventories.fields.order', 'inventories.fields.update', 'inventories.fields.destroy',
    ];

    $classification = [];

    foreach (['read' => $read, 'write' => $write, 'manage' => $manage] as $ability => $names) {
        foreach ($names as $name) {
            $classification['api.v1.'.$name] = $ability;
        }
    }

    return $classification;
}

/**
 * The routes of the API that only the web front may call, with a token or not: the person at their browser, never a script.
 *
 * @return list<string>
 */
function tokenSessionOnlyRoutes(): array
{
    return array_map(fn (string $name): string => 'api.v1.'.$name, [
        'auth.code', 'auth.verify', 'auth.logout', 'me.update', 'me.export', 'me.current-workspace', 'me.sign-out-everywhere',
        'me.deletion-code', 'me.destroy', 'admin.users.index', 'admin.users.store', 'admin.users.update', 'admin.users.destroy',
        'admin.settings.show', 'admin.settings.update', 'me.tokens.index', 'me.tokens.store', 'me.tokens.destroy',
    ]);
}

/**
 * The routes under `api/` whose name does not start with `api.v1.` (none at all, or another one), each as "METHOD uri". The tests that
 * classify and document the routes work by name: a route they cannot name escapes them, and so would escape the guard-rails.
 *
 * @return list<string>
 */
function apiStrayRoutes(): array
{
    $stray = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if (str_starts_with($route->uri(), 'api/') && ($name === null || ! str_starts_with($name, 'api.v1.'))) {
            $stray[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
        }
    }

    sort($stray);

    return $stray;
}

/**
 * The routes of the API (`api/v1/...`), by name. Fails the test that asks when a route under `api/` has no name or one outside
 * `api.v1.` (see {@see apiStrayRoutes()}): it would be neither classified nor documented by the tests that go through this list.
 *
 * @return array<string, Route>
 */
function apiRoutes(): array
{
    $stray = apiStrayRoutes();

    expect($stray)->toBe([], 'Name these routes under api/ "api.v1.<name>", so that they are classified and documented (they escape the tests otherwise): '.implode(', ', $stray));

    $routes = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if ($name !== null && str_starts_with($name, 'api.v1.')) {
            $routes[$name] = $route;
        }
    }

    return $routes;
}

/**
 * The middleware of a route, as the names of the classes (with their parameters after a colon), aliases and groups resolved.
 *
 * @return list<string>
 */
function apiRouteMiddleware(Route $route): array
{
    return array_values(array_map('strval', app('router')->gatherRouteMiddleware($route)));
}

/**
 * The address of a route, with a made-up identifier for each of its parameters (the ULID of a row that does not exist).
 */
function tokenRouteUri(Route $route): string
{
    $uri = '/'.$route->uri();

    foreach ($route->parameterNames() as $parameter) {
        $uri = str_replace('{'.$parameter.'}', '01j0000000000000000000000x', $uri);
    }

    return $uri;
}

/**
 * A call to the API the way a script makes it: nothing but the token (no cookie, no session, no Origin), unless the headers say so.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function tokenCall(object $test, string $token, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    // A client of its own: the session, the headers and the user the guards remembered from the previous call are not this one's.
    $test->flushSession();
    $test->flushHeaders();
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($method, $uri, $data, $headers);

    // The token stays out of the next call, which may be a person's at their browser.
    $test->flushHeaders();

    return $response;
}

/**
 * Sends a file to the API the way a script does: a multipart form with nothing but the token (no cookie, no session, no Origin).
 *
 * @param  array<string, mixed>  $data  More parts of the form.
 * @param  array<string, string>  $headers
 */
function tokenUpload(object $test, string $token, string $uri, UploadedFile $file, array $data = [], array $headers = []): TestResponse
{
    $test->flushSession();
    $test->flushHeaders();
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->post($uri, ['file' => $file] + $data, $headers + ['Accept' => 'application/json']);

    $test->flushHeaders();

    return $response;
}

/**
 * A call that carries a session (the person is signed in at their browser, the headers are the front's) and, on top of it, a token.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function tokenWithSessionCall(object $test, User $as, string $token, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    $test->flushSession();
    $test->flushHeaders();
    app('auth')->forgetGuards();
    $test->actingAs($as);

    $response = $test->withToken($token)->json($method, $uri, $data, frontend($headers));

    $test->flushHeaders();

    return $response;
}

/**
 * The text of a new API token of a person (the part that goes after "Bearer ").
 *
 * @param  list<string>  $abilities  What the token may do: `read`, `write`, `manage`, or `*` for all of it.
 */
function tokenFor(User $user, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): string
{
    return $user->createToken('script', $abilities, $expiresAt)->plainTextToken;
}
