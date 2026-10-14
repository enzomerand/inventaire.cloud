# inventaire.cloud — web application

The web application behind [inventaire.cloud](https://inventaire.cloud), a free and simple inventory tool for professionals and individuals.

It is built **API-first**: the pages are classic server-rendered navigation, but every action goes through one HTTP helper so the same JSON API (`/api/v1`, coming in a later lot) can serve the web front, the native apps and third-party tools.

## Stack

| Layer      | Choice                                                                                  |
|------------|-----------------------------------------------------------------------------------------|
| Backend    | Laravel 13, PHP 8.3+                                                                    |
| Database   | PostgreSQL, MySQL or MariaDB, never engine-specific SQL (SQLite is not supported)       |
| Front end  | Blade + [Tabler](https://tabler.io) (Bootstrap 5) + Alpine.js (CSP build), bundled by Vite |
| Icons      | [`secondnetwork/blade-tabler-icons`](https://github.com/secondnetwork/blade-tabler-icons) |
| Tests      | Pest (PHP), Vitest + jsdom (JS)                                                         |
| Quality    | Pint, Larastan (level 8), ESLint, Prettier                                              |

Privacy by design: no CDN, no Google Fonts, no external request from the browser. The Inter font and every asset are served by the application itself.

## Requirements

- PHP 8.3 or newer (developed on 8.5) with the `pdo_pgsql` and `pdo_mysql` extensions (`pdo_mysql` serves both MySQL and MariaDB)
- [Composer](https://getcomposer.org) 2
- Node.js 20.19+ / 22.12+ and npm
- [Docker](https://www.docker.com) with Compose, for the development database and mail services

## Development setup

Run every command from this `web/` directory.

1. **Start the services** (PostgreSQL, MySQL, MariaDB, Mailpit) and wait until they are healthy:

   ```bash
   docker compose -f compose.dev.yaml up -d --wait
   ```

   | Service    | Host port                          | Credentials                                  |
   |------------|------------------------------------|----------------------------------------------|
   | PostgreSQL | `54329`                            | database `inventaire`, user `inventaire`, password `secret` |
   | MySQL      | `33069`                            | same as above                                |
   | MariaDB    | `33070`                            | same as above                                |
   | Mailpit    | `1025` (SMTP), `8025` (web UI)     | none                                         |

   Each database also has an `inventaire_test` schema reserved for the test suite. These scripts only run when a volume is first created; use `docker compose -f compose.dev.yaml down -v` to start from scratch.

2. **Install and configure** the application. `composer setup` installs the dependencies, creates `.env` from `.env.example`, generates the application key, builds the assets, then runs `php artisan inventaire:install --from-env`: it checks the services configured in `.env` (database, mail, storage), runs the migrations and marks the instance as installed (see [Installing an instance](#installing-an-instance)):

   ```bash
   composer setup
   ```

   `.env.example` points at the PostgreSQL service. To develop on MySQL or MariaDB instead, uncomment the matching block in `.env` (and comment the PostgreSQL one), then run `php artisan migrate:fresh`.

3. **Run the application.** `composer dev` starts the PHP server, the queue worker, the log tail and the Vite dev server together:

   ```bash
   composer dev
   ```

   The app is then available on <http://localhost:8000> and the mail inbox on <http://localhost:8025>.

## Installing an instance

A new instance is not usable until it is installed. Until then **every web URL redirects to `/install`** (JSON requests get a `503` with `"code": "not_installed"`), and the application runs without a database or a complete `.env`: sessions and cache use files and jobs run inline. The `APP_KEY` is generated on the first web access and written to `.env` (or a static error page explains what to fix when `.env` is not writable). Once the installation is finished, every `/install` URL answers `404`.

There are three ways to install, and they share the same checks:

| Way                              | Use it for                                                              |
|----------------------------------|-------------------------------------------------------------------------|
| The **web wizard** at `/install` | A server you reach with a browser                                       |
| `php artisan inventaire:install` | A terminal: interactive prompts, or options for scripts                 |
| `inventaire:install --from-env`  | Configuration that already exists (Docker variables, `composer setup`)  |

The installer keeps its own files (installed marker, progress, setup token, locks) in `storage/installer/`, readable by the owner and the group so that the web server and the person running the console installer can both use them; `INSTALLER_STATE_PATH` relocates the folder (for example onto a persistent volume). Steps are registered once, in the `App\Installer\InstallStep` enum: the web routes, the wizard and the console installer all iterate over it.

### The setup token (web wizard)

Whoever can open `/install` could point a fresh instance at their own database, so the wizard asks for a one-time **setup token** first. It is created on the first web access, stored in `storage/installer/setup-token` (readable by the owner and the group, so the web server and the person running the console installer can both use it), written to its own log file, `storage/logs/installer.log` (a dedicated channel, so it does not depend on `LOG_CHANNEL`, `LOG_STACK` or `LOG_LEVEL`) and printed by:

```bash
php artisan inventaire:install-token
```

The token is compared in constant time, the form accepts 5 attempts per minute and per IP address, the old session is destroyed and a new id issued once it is accepted, and the token is deleted when the installation completes. If the log cannot be written, the page still works and `inventaire:install-token` still prints the token. The console installer does not need it: running it already proves access to the server.

### Behind a reverse proxy

Behind a reverse proxy or a load balancer every request seems to come from the proxy, so the 5-attempts throttle would count all visitors together. Set `TRUSTED_PROXIES` in `.env` (or the environment) to a comma-separated list of the proxies' addresses or CIDR ranges, or `*` for any proxy; it is empty by default (no proxy is believed, so a forged `X-Forwarded-For` header is worthless). The application then reads the real client address from `X-Forwarded-For`.

### Trust model

Everything the wizard does is behind the setup token: whoever holds it is trusted like the server administrator. In particular the checks connect to the addresses typed in the forms (database, SMTP, S3 endpoint, Documenso), so they can reach hosts on the internal network, by design: do not hand the token to somebody who should not be able to probe your network. Checks use short timeouts (5 seconds to connect) and never show passwords or API tokens in their messages.

### The steps

The wizard is one page with a progress bar and one AJAX endpoint per step. Each step **tests the real service before saving**, writes its values to `.env` immediately and is recorded in `storage/installer/progress.json`, so an interrupted installation resumes where it stopped (the token is asked again in a new browser session).

1. **Requirements**: the PHP version required by `composer.json`, the extensions (`pdo` with `pdo_pgsql` or `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `intl`, `gd` or `imagick`, `zip`, `curl`) and write access to `storage/`, `bootstrap/cache/` and `.env`.
2. **Database**: PostgreSQL 14+, MySQL 8.0+ or MariaDB 10.11+. The connection is tested with a temporary connection; a MariaDB server selected as "MySQL" (or the reverse) is refused, so is a database that holds anything but a previous run of this installer is refused (it must be empty, or have a `migrations` table whose entries are all this application's migrations), and the user must be able to create tables. An interrupted installation can be resumed on the same database.
3. **Application**: public URL, default language and timezone.
4. **Email**: SMTP settings and a test address. A 6-digit code is sent there and the step is only validated once it is typed back (valid 15 minutes, 5 attempts; the pending values are kept encrypted in the session).
5. **File storage**: the local disk, or an S3-compatible service (key, secret, region, bucket, endpoint, path-style). The check writes, reads and deletes a temporary file.
6. **Documenso** (optional, can be skipped): instance URL and API token. The check is one authenticated call to the Documenso API, `GET {url}/api/v2/envelope?page=1&perPage=1`, with the bare token in the `Authorization` header ([Documenso API reference](https://docs.documenso.com/docs/developers/api)).
7. **Installation**: runs the migrations, switches the instance to `APP_ENV=production` / `APP_DEBUG=false` (the web wizard always does, except for values the environment imposes), clears the configuration cache, writes the "installed" marker and deletes the token. Anything that has to happen once the tables exist but before the instance is marked installed (the accounts lot will create the first administrator there) plugs in as a `App\Installer\PostMigrationHook`, tagged `installer.post-migration`: hooks run after the migrations, must be idempotent (the installation can be run again after a failure) and abort it by throwing an `InstallationException`.

Creating the first administrator account is not part of the installer yet (it comes with the accounts lot).

### Values provided by the environment

With Docker, configuration usually arrives as real environment variables, which Laravel prefers over `.env`. The installer records the environment the process started with, at the very top of `bootstrap/app.php` (before `.env` is read): every variable in it is **imposed**, even when `.env` holds the same value. The wizard shows imposed values read-only with a "set by the environment" badge, probes them as is, and never writes them. Secrets are never sent back to the browser (an empty box means "unchanged"; to store an empty password on purpose, tick "No password" next to it), and a `.env` that cannot be written is reported with a clear message instead of being silently ignored.

`DB_URL` and `MAIL_URL` are honoured: when one is set (environment or `.env`), the corresponding step treats the connection as provided. Its fields are read-only and masked (a URL usually contains a password), and the checks and the migrations use exactly the configuration the application will use, URL included.

When every setting the installation needs is provided by the environment, nothing has to be written: the wizard does not require `.env` or `bootstrap/cache/` to be writable, which suits read-only containers.

When `.env` does not exist, the installer creates it from `.env.example`, minus the development defaults: a file created by the installer (on the first web access or by the console command) starts with `APP_ENV=production`, `APP_DEBUG=false`, no database or SMTP credentials, no SMTP host and no sender address, instead of the example's local services. If you copy `.env.example` yourself on a server, set these values yourself. For development, run `composer setup`, which copies the example first and leaves the environment alone.

### From the terminal

```bash
php artisan inventaire:install                 # interactive prompts, pre-filled from .env
php artisan inventaire:install --no-interaction \
    --db-driver=pgsql --db-host=127.0.0.1 --db-port=5432 --db-name=inventaire --db-user=inventaire \
    --app-url=https://inventory.example.com --locale=fr --timezone=Europe/Paris \
    --mail-host=smtp.example.com --mail-port=587 --mail-from-address=hello@example.com --mail-from-name="My Company" \
    --storage=local
php artisan inventaire:install --from-env      # validate the current configuration and finalize
```

- In interactive mode every value is prompted and the mail step is proven with a code, like in the web wizard.
- In non-interactive mode (`--no-interaction`) the values come from the options (one per wizard field: `php artisan inventaire:install --help`), completed by what `.env` and the environment already provide; the first failing check stops the command with a non-zero exit code. The SMTP server is checked with an authenticated SMTP session, since nobody can read a code. `--skip-mail-test` skips that check (with a warning) and is refused when prompts are possible. Options end up in the shell history and the process list: for passwords, prefer `.env` or the environment.
- Switching to production mode is asked interactively (default yes, except on a `local` instance) and done by default in non-interactive mode; `--keep-environment` leaves `APP_ENV` and `APP_DEBUG` alone. `--db-no-password` and `--mail-no-password` store an empty password on purpose.
- `--from-env` asks nothing, never touches `APP_ENV` or `APP_DEBUG` (the developer path) and writes nothing to `.env` (it does not even need it to be writable): it validates the current configuration with the same live checks, then runs the migrations and finalizes. It is what `composer setup` runs, and what a container entrypoint can run.

### Troubleshooting

- *I lost the setup token*: `php artisan inventaire:install-token`.
- *The wizard keeps showing finished steps*: the progress lives in `storage/installer/progress.json`; delete that file to start over (the configuration stays in `.env`).
- *I want to run the wizard again on an installed instance*: delete `storage/installer/installed.json`. This only re-enables the installer; it does not touch the database.

## Quality commands

| Command                | What it does                                                         |
|------------------------|----------------------------------------------------------------------|
| `composer lint`        | Checks PHP code style with Pint (`pint --test`)                      |
| `composer format`      | Fixes PHP code style with Pint                                       |
| `composer analyse`     | Runs Larastan at level 8 on `app`, `config`, `routes`, `database`    |
| `composer test`        | Runs the Pest suite on PostgreSQL, MySQL, then MariaDB (stops at the first failure) |
| `composer test:pgsql`  | Runs the suite on the PostgreSQL service only                        |
| `composer test:mysql`  | Runs the suite on the MySQL service only                             |
| `composer test:mariadb` | Runs the suite on the MariaDB service only                          |
| `npm run lint`         | ESLint, then Prettier in check mode (JS, CSS, JSON)                  |
| `npm run format`       | Fixes JS, CSS and JSON files                                         |
| `npm test`             | Runs the Vitest suite                                                |
| `npm run build`        | Builds the production assets into `public/build`                     |

All PHP files under `app`, `config`, `routes`, `database` and `tests` declare `strict_types`, and Larastan runs without a baseline: fix new findings instead of silencing them.

## Testing on PostgreSQL, MySQL and MariaDB

The application has to behave the same on all three engines, and SQLite is deliberately not supported. The database tests (`RefreshDatabase`) run the real migrations, so running the suite on each engine catches engine-specific SQL early. The services must be running (`docker compose -f compose.dev.yaml up -d --wait`):

```bash
composer test         # PostgreSQL, MySQL, then MariaDB; stops at the first failure
composer test:pgsql   # PostgreSQL only
composer test:mysql   # MySQL only
composer test:mariadb # MariaDB only
```

`phpunit.xml` points at the PostgreSQL service and its dedicated `inventaire_test` schema, so `composer test:pgsql` (and a plain `php artisan test`) need no extra setup; `composer test:mysql` and `composer test:mariadb` override `DB_*` to use their own service and its `inventaire_test` schema.

The tests never touch the real `.env`, installer state, cached configuration or storage: `phpunit.xml` points `INSTALLER_STATE_PATH`, `INSTALLER_ENV_FILE`, `APP_CONFIG_CACHE` and `CACHE_FILE_PATH` at a throw-away folder below `storage/framework/testing/`, emptied around every test (`tests/Support/InstallerSandbox.php`). Every test boots in the "installed" state, so tests that predate the installer are unaffected; a test that needs a fresh instance calls `$this->bootInstaller(installed: false)`. Run the suite sequentially: the sandbox folder is shared. (`composer test` itself starts with `php artisan config:clear`, which does clear the real cached configuration.)

Safety net: `tests/TestCase.php` refuses to boot (before any migration) unless the configured database name ends with `_test`. A stale cached config (`php artisan config:clear` fixes it) or a `DB_DATABASE` exported in your shell can therefore never wipe the development database.

## Project structure

```text
app/
  Console/Commands/ inventaire:install (console wizard) and inventaire:install-token
  Http/
    Controllers/    Invokable or resourceful controllers (Install/ holds the wizard endpoints)
    Middleware/     SetLocale (global): applies the language chosen by LocaleResolver;
                    RedirectIfNotInstalled (global), AbortIfInstalled, AuthorizeSetupToken: the install gate
    Requests/       Form requests (validation)
  Installer/        Installation state, .env writer, setup token, probes, finalizer (see "Installing an instance")
  Providers/        InstallerServiceProvider: pre-install runtime and application key
  Services/         LocaleResolver: which language a request is answered in
  View/Components/  Blade component classes (ClientMessages)
config/             Laravel configuration (app.php lists the supported locales)
lang/               fr.json (UI strings) and fr/ (framework messages)
resources/
  css/app.css       Tabler, the self-hosted Inter font and project tokens
  js/
    app.js          Entry point: Tabler, Alpine, stores, components
    lib/            http.js (the one HTTP helper), i18n.js, notify.js, page.js
    stores/         Alpine stores (toasts)
    components/     Alpine components (toasts, locale switcher, install wizard)
  views/
    layouts/        app.blade.php, the Tabler page layout
    components/     Anonymous Blade components (<x-toasts />, <x-locale-switcher />)
routes/web.php      Web routes
tests/
  Feature/          Pest feature tests (HTTP, database)
  Unit/             Pest unit tests
  js/               Vitest tests
compose.dev.yaml    Development services
```

## Conventions

- **One HTTP helper.** Scripts never call `fetch` directly: they use `http` from `resources/js/lib/http.js` (see below).
- **No display text in JavaScript.** Strings that scripts need are translated on the server and embedded by `<x-client-messages />`; scripts read them with `t('key')`.
- **Alpine, CSP build.** The app uses `@alpinejs/csp`, so a strict Content-Security-Policy without `'unsafe-eval'` can be enforced (the header itself arrives in a later lot). In practice:
  - every component is registered in `resources/js/app.js` with `Alpine.data('name', factory)` and referenced by name in the markup (`x-data="toasts"`), never with an inline object;
  - expressions in templates stay simple: property access, method calls with arguments, comparisons, `!`, `&&`/`||`, ternaries and string `+`. Arrow functions, template literals, `;` statements and Blade values interpolated inside an expression are not allowed: put that logic in a component method;
  - a component gets its values from `data-*` attributes (`data-url` on the root, `data-locale` on an item), read through `$root` or `event.currentTarget`, and translated texts are rendered by Blade as plain attributes;
  - `tests/js/templates/alpine-csp.test.js` scans every Blade template for forbidden syntax and mounts the toast markup with the real CSP build.
- **Icons.** Use `<x-tabler-*>` components and do not pass a `class` attribute: the SVG files already have one, which would render the attribute twice. Size and space icons from a parent element.
- **Documentation.** Every class and public method carries a PHPDoc/JSDoc block that explains the why, not only the what.
- **Language.** Code, identifiers, comments and commit messages are in English.

## HTTP helper

`http.get/post/put/patch/delete` is the contract every action relies on. It sends JSON with `Accept`, `Accept-Language`, `X-Requested-With` and the CSRF token (`X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie, falling back to the `csrf-token` meta tag, so rotation after login or logout is followed). It resolves the parsed JSON, `null` for `204`, or a `Blob`/text with `{ responseType: 'blob' | 'text' }`; a `FormData` body is sent as is. `{ timeout }` (default 30 s, covering the body too) and `{ signal }` can be combined.

Failures throw an `HttpError` (`status`, `message`, `errors`, `code` from `body.code`, `retryAfter`, `kind`, `reported`). Raw response messages are never shown, except the server-translated validation messages of a `422`; everything else is looked up by key in the strings embedded by `<x-client-messages />`.

| Situation              | Central handling (skip all with `{ silent: true }`)                                              |
|------------------------|--------------------------------------------------------------------------------------------------|
| `401`                  | Toast, then redirect to the `login-url` meta tag if the page has one, else reload                |
| `403`, `404`           | Toast                                                                                            |
| `419`                  | Refresh the token (`csrf-refresh-url` meta tag) and replay once; only a second failure toasts and reloads |
| `422`, other `4xx`     | Nothing: the caller handles it (`errors`, `code`)                                                |
| `429`                  | Toast; `retryAfter` holds the `Retry-After` delay in seconds                                     |
| `5xx`                  | Toast                                                                                            |
| Network, timeout       | Toast (`HttpError.status === 0`, `kind` is `network` or `timeout`)                               |
| `2xx` that is not JSON | Toast and `HttpError` with `kind === 'unexpected_response'`                                      |

A request cancelled through the caller's `signal` rejects with the raw `AbortError` and no toast.

## Translations

French is the default language and English the fallback. Interface strings are written in English in the code with `__()` and translated in `lang/fr.json`; framework messages (validation, authentication, pagination, passwords) come from [laravel-lang](https://laravel-lang.com).

The language of a request is chosen by `App\Services\LocaleResolver`, trying in order: the signed-in user's saved preference (a documented hook, filled in with the accounts lot), the one-year `locale` cookie written by `PUT /locale`, the best `Accept-Language` match, then the default. The `SetLocale` middleware is global and runs first, so 404 pages, expired-session errors and the future API are localized too, and responses carry `Content-Language` and `Vary: Accept-Language, Cookie`. The `locale` cookie is excluded from encryption because its value is validated against the supported list on every read.

To add a language, for example Spanish:

1. Add `es` to `locale_names` in `config/app.php` (its keys are the supported locales).
2. Run `php artisan lang:add es` for the framework messages and `lang/es.json`, then translate the project strings in it.

The language switcher and the `PUT /locale` endpoint pick the new locale up from the configuration.

## License

Released under the [GNU Affero General Public License v3.0](../LICENSE) (AGPL-3.0). If you run a modified version as a network service, you must offer its source code to your users (section 13): set `APP_REPOSITORY_URL` in `.env` to where your fork's source is published. The page footer links to that URL, and to its `LICENSE` file, instead of the upstream repository.
