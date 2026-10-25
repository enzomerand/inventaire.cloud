<?php

declare(strict_types=1);

use App\Http\Middleware\PaceApiTokens;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Http\Middleware\RefuseApiTokens;
use App\Http\Middleware\RequireStatefulRequest;
use App\Http\Middleware\RequireTokenAbility;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPermissionsPolicy;
use App\Http\Middleware\SetReferrerPolicy;
use App\Http\Middleware\SetRobotsTag;
use App\Http\Responses\MembershipError;
use App\Installer\ProcessEnvironment;
use App\Services\LocaleResolver;
use App\Services\TrustedProxies;
use App\Workspaces\Exceptions\MembershipRefused;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Before anything reads .env: the installer must know which variables the process really started with.
ProcessEnvironment::capture();

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The Referrer-Policy, Permissions-Policy and X-Robots-Tag headers go out with every answer, so they come first
        // (outermost). Then the language (so every answer is localized), then the install gate: it must precede everything
        // that could need a database, a session or an application key.
        $middleware->prepend([
            SetReferrerPolicy::class, SetPermissionsPolicy::class, SetRobotsTag::class, SetLocale::class, RedirectIfNotInstalled::class,
        ]);
        $middleware->encryptCookies(except: [LocaleResolver::COOKIE_NAME]);

        // The web front calls the API with its session cookie: Sanctum makes the requests that come from the
        // application's own host (config/sanctum.php) stateful, every other client stays stateless.
        $middleware->statefulApi();

        // `token:read`, `token:write` and `token:manage` on the routes an API token may reach (see routes/api.php).
        $middleware->alias(['token' => RequireTokenAbility::class]);

        // What a request has to pass, in this order: the authentication; the pace of its token (every request a token makes is
        // counted, the ones that are refused as well); the refusals (a token on a route for the web front, a token that lacks the
        // ability); then the limits of the endpoints; then the models of the route are looked up. The limits of the endpoints (an
        // export, a workspace, a token to make...) are a person's own: a request that is refused before it does anything must not use
        // them up, or a leaked token that can only read would lock its owner out of their own account. The ability is judged before the
        // models are looked up too: a token that may not write is told so for any inventory, whether it exists, belongs to somebody
        // else or is made up, instead of a 404 that tells them apart. Each call puts the class right before the throttles, so they
        // end up in the order of the calls.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: PaceApiTokens::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RefuseApiTokens::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RequireStatefulRequest::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RequireTokenAbility::class);

        // A signed-out visitor of a page goes to the login page; an API client has nowhere to be redirected to
        // and gets a 401 in JSON (the default would also fail while no "login" route exists).
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : route('login'));

        // Someone already signed in who opens the login page goes to their space.
        $middleware->redirectUsersTo(fn (Request $request): string => route('app'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers JSON, whatever the client asked for: a script that forgets the Accept header must
        // not receive an HTML error page or a redirect to the login page. So do the webhooks of other services
        // (`/webhooks/*`), machines that read nothing else.
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception): bool => $request->is('api/*', 'webhooks/*') || $request->expectsJson());

        // A client that is slowed down is told so the way the other refusals are, with a `code` to switch on: the message is for people.
        // The headers the limiter chose (Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining) stay, since they are what a script waits by.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*', 'webhooks/*')) {
                return null;
            }

            return new JsonResponse(
                ['message' => __('Too many attempts. Please wait a moment before trying again.'), 'code' => 'too_many_requests'],
                429,
                $exception->getHeaders(),
            );
        });

        // A change to who belongs to a workspace that the rules refuse (not the owner, a quota, a resend too soon...) is told like the other
        // refusals: a `message` and the `code` to switch on (see MembershipError). The services raise it, the controllers do not catch it, and it is
        // an answer, not a fault to log.
        $exceptions->dontReport(MembershipRefused::class);
        $exceptions->render(fn (MembershipRefused $refused): JsonResponse => MembershipError::response($refused));

        // A row that is not there is a plain 404. The framework's own message names the model class and the key that was
        // looked up, which tells a row that does not exist from one the person may not see: that one is a 404 too, with
        // the standard message (see the policies, "denyAsNotFound").
        $exceptions->map(ModelNotFoundException::class, fn (ModelNotFoundException $exception): NotFoundHttpException => new NotFoundHttpException('Not Found', $exception));
    })->create();

// APP_ENVIRONMENT_FILE moves the configuration file out of the application folder (a Docker volume): Laravel and the
// installer, which falls back to the file Laravel loads, then use the same one. Unset, nothing changes.
$environmentFile = ProcessEnvironment::environmentFile();

if ($environmentFile !== null) {
    $app->useEnvironmentPath(dirname($environmentFile));
    $app->loadEnvironmentFrom(basename($environmentFile));
}

// Read once the configuration (and so .env) is loaded: the middleware callback above runs earlier.
$app->booted(fn () => TrustedProxies::apply($app->make('config')->get('app.trusted_proxies')));

return $app;
