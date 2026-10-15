<?php

declare(strict_types=1);

use App\Installer\Settings\DatabaseSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mime\Email;
use Tests\Support\ConcurrentChildren;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the whole Laravel application through the base TestCase.
| Unit tests stay framework-free on purpose: they must run without a container.
|
*/

pest()->extend(TestCase::class)->in('Feature');

// The installer's messages are asserted on their English source text. These tests install for real into the test
// database, which creates the administrator account there for good (no test transaction wraps them): remove it, or
// the tests that expect an empty users table would depend on the order in which the files run.
pest()->in('Feature/Installer')
    ->beforeEach(function (): void {
        $this->useLanguage('en');
    })
    ->afterEach(function (): void {
        // By name: a test may have left the default connection pointing elsewhere, on purpose. Never delete from a
        // database that is not clearly a test one, and let any failure surface rather than hide a leftover account.
        $name = getenv('DB_CONNECTION') ?: 'pgsql';

        TestDatabaseGuard::assertSafe(app('config'), $name);

        $connection = DB::connection($name);

        // Workspaces first: deleting a user takes its memberships along but not the workspaces they leave behind.
        if ($connection->getSchemaBuilder()->hasTable('workspaces')) {
            $connection->table('workspaces')->delete();
        }

        if ($connection->getSchemaBuilder()->hasTable('users')) {
            $connection->table('users')->delete();
        }
    });

/**
 * Database settings pointing at the engine the suite currently runs on (the
 * dedicated test database), with optional overrides.
 *
 * @param  array<string, mixed>  $override
 */
function testDatabaseSettings(array $override = []): DatabaseSettings
{
    $driver = config('database.default');
    $connection = config("database.connections.{$driver}");

    return DatabaseSettings::fromArray(array_merge([
        'driver' => $driver,
        'host' => $connection['host'],
        'port' => $connection['port'],
        'database' => $connection['database'],
        'username' => $connection['username'],
        'password' => $connection['password'],
    ], $override));
}

/**
 * Whether something listens on the local Mailpit SMTP port of compose.dev.yaml.
 */
function mailpitIsUp(): bool
{
    $socket = @fsockopen('127.0.0.1', 1025, $errno, $error, 0.5);

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}

/**
 * The process environment of a container configured only through variables,
 * pointing at the test database.
 *
 * @return array<string, string>
 */
function containerEnvironment(): array
{
    $db = testDatabaseSettings();

    return [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => $db->driver, 'DB_HOST' => $db->host, 'DB_PORT' => (string) $db->port, 'DB_DATABASE' => $db->database,
        'DB_USERNAME' => $db->username, 'DB_PASSWORD' => $db->password,
        'APP_URL' => 'https://inventory.example.test', 'APP_LOCALE' => 'fr', 'APP_TIMEZONE' => 'UTC',
        'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587',
        'MAIL_FROM_ADDRESS' => 'hello@example.test', 'MAIL_FROM_NAME' => 'My Shop', 'FILESYSTEM_DISK' => 'local',
        'ADMIN_NAME' => 'Jane Doe', 'ADMIN_EMAIL' => 'jane@example.test',
    ];
}

/**
 * The headers of a call made by the web front: an API request from the application's own host, which Sanctum treats
 * as stateful (session and CSRF protection). Without them an API call is stateless, as a script's would be.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function frontend(array $extra = []): array
{
    $origin = rtrim(config()->string('app.url'), '/');

    return $extra + ['Origin' => $origin, 'Referer' => $origin.'/login'];
}

/**
 * The timezone of the current database session, as the engine reports it (an offset for MySQL and MariaDB, a name for
 * PostgreSQL).
 */
function sqlSessionTimezone(): string
{
    if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        return (string) DB::selectOne('select @@session.time_zone as zone')->zone;
    }

    return (string) DB::selectOne('show time zone')->TimeZone;
}

/**
 * A date as the database holds it (the first row of the table), without any conversion by the models.
 */
function storedDate(string $table, string $column): ?string
{
    $value = DB::table($table)->value($column);

    return $value === null ? null : (string) $value;
}

/**
 * Asks for a sign-in code the way the web front does.
 *
 * @param  array<string, string>  $headers
 */
function askForCode(object $test, string $email, array $headers = []): TestResponse
{
    return $test->postJson('/api/v1/auth/code', ['email' => $email], frontend($headers));
}

/**
 * Asks for a code the way the web front does, and returns it as read from the email.
 */
function receiveCode(object $test, string $email, string $language = 'en'): string
{
    askForCode($test, $email, ['Accept-Language' => $language])->assertOk();

    return lastSentCode();
}

/**
 * Types a code back the way the web front does.
 *
 * @param  array<string, mixed>  $fields
 * @param  array<string, string>  $headers
 */
function typeCode(object $test, string $email, string $code, array $fields = [], array $headers = []): TestResponse
{
    return $test->postJson('/api/v1/auth/verify', ['email' => $email, 'code' => $code] + $fields, frontend($headers));
}

/**
 * Wrong sign-in codes counted for an address in the current period, read where the application keeps the count (the
 * rate limiter's cache, under the key LoginFailures uses).
 *
 * @param  string|null  $store  Cache store to read, or null for the one the rate limiter uses.
 */
function loginFailureCount(string $email, ?string $store = null): int
{
    $limiter = $store === null ? app(RateLimiter::class) : new RateLimiter(Cache::store($store));

    return (int) $limiter->attempts('login-failures:'.sha1($email));
}

/**
 * A code that is not the pending one.
 */
function wrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

/**
 * Holds back what the application defers to after the response (the mails of codes and welcomes), so that a test can
 * look at the state between the answer and the end of the request, then let it run.
 *
 * Without this, the test client runs the deferred work as the request terminates, before the call returns. The
 * collection that comes back has a `release()` method that runs what was held.
 */
function holdDeferred(): DeferredCallbackCollection
{
    $held = new class extends DeferredCallbackCollection
    {
        public bool $holding = true;

        public function invokeWhen(?Closure $when = null): void
        {
            if (! $this->holding) {
                parent::invokeWhen($when);
            }
        }

        /**
         * Runs what was deferred, as the end of the request does for a response below 400.
         */
        public function release(): void
        {
            $this->holding = false;
            $this->invokeWhen(fn (): bool => true);
            $this->holding = true;
        }
    };

    app()->instance(DeferredCallbackCollection::class, $held);

    return $held;
}

/**
 * The emails the application sent so far in this test (the "array" mailer keeps them).
 *
 * @return list<Email>
 */
function sentMails(): array
{
    $transport = app('mailer')->getSymfonyTransport();

    return $transport->messages()->map(fn ($message) => $message->getOriginalMessage())->values()->all();
}

/**
 * The 6-digit code in the last email sent, as the person would read it.
 */
function lastSentCode(): string
{
    $mails = sentMails();

    expect($mails)->not->toBe([], 'No email was sent.');

    preg_match('/\b(\d{6})\b/', (string) end($mails)->getTextBody(), $matches);

    return $matches[1] ?? '';
}

/**
 * A query failure as the PDO drivers report it: the SQLSTATE, the error code of the driver and its message.
 */
function queryFailure(string $state, int|string $code, string $message): QueryException
{
    $driver = new PDOException($message);
    $driver->errorInfo = [$state, $code, $message];

    return new QueryException('testing', 'select 1', [], $driver);
}

/**
 * The failure of a transaction that the database picked as the loser of a deadlock, the way MySQL and MariaDB report it.
 */
function lostDeadlock(): QueryException
{
    return queryFailure('40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction');
}

/**
 * The SQL statements run against the database while a callback runs, in order and without their values.
 *
 * @return list<string>
 */
function statementsOf(Closure $callback): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $callback();

    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $statements;
}

/**
 * Empties what the concurrency tests commit (they run on the test database only: see TestDatabaseGuard).
 */
function removeConcurrencyRows(): void
{
    DB::table('workspaces')->delete();
    DB::table('users')->delete();
}

/**
 * Runs actions in separate PHP processes that all start together, and returns what they reported as sorted words
 * ("deleted", "demoted", "refused:last_administrator"...).
 *
 * @param  list<list<int|string>>  $commands  The arguments of each process after the go file: the action, then its ids.
 * @param  array<string, string>  $environment  Added to the environment of the processes (a quota, say).
 * @return list<string>
 */
function actTogether(string $directory, array $commands, array $environment = []): array
{
    $answers = ConcurrentChildren::run($directory, base_path('tests/Support/concurrent-action.php'), $commands, $environment);
    $words = array_map(fn (array $answer): string => $answer['result'].(isset($answer['reason']) ? ':'.$answer['reason'] : ''), $answers);
    sort($words);

    return $words;
}
