# inventaire.cloud — web application

The web application behind [inventaire.cloud](https://inventaire.cloud), a free and simple inventory tool for professionals and individuals.

It is built **API-first**: the pages are classic server-rendered navigation, but every action goes through one HTTP helper so the same JSON API (`/api/v1`) can serve the web front, the native apps and third-party tools.

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

4. **Sign in.** There are no passwords (see [Accounts and sign-in](#accounts-and-sign-in)). Open <http://localhost:8000/login>, give the `ADMIN_EMAIL` of `.env` (`admin@example.com` in the example, created as an administrator by `composer setup`) and read the 6-digit code in the mail inbox. `php artisan db:seed` adds a second administrator, `test@example.com`.

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

Whoever can open `/install` could point a fresh instance at their own database, so the wizard asks for a one-time **setup token** first. It is created on the first web access, stored in `storage/installer/setup-token` (readable by the owner and the group, so the web server and the person running the console installer can both use it), written to its own log file, `storage/logs/installer.log` (a dedicated channel, so it does not depend on `LOG_CHANNEL`, `LOG_STACK` or `LOG_LEVEL`; readable by the owner and the group only) and printed by:

```bash
php artisan inventaire:install-token
```

The token is compared in constant time, the form accepts 5 attempts per minute and per IP address, the old session is destroyed and a new id issued once it is accepted, and the token is deleted when the installation completes. If the log cannot be written, the page still works and `inventaire:install-token` still prints the token. The console installer does not need it: running it already proves access to the server.

### Behind a reverse proxy

Behind a reverse proxy or a load balancer every request seems to come from the proxy, so the 5-attempts throttle would count all visitors together. Set `TRUSTED_PROXIES` in `.env` (or the environment) to a comma-separated list of the proxies' addresses or CIDR ranges, or `*` for any proxy; it is empty by default (no proxy is believed, so a forged `X-Forwarded-For` header is worthless). The application then reads the real client address from `X-Forwarded-For`.

With an `https://` `APP_URL` the session and CSRF cookies are `Secure` by default: a browser only sends them over HTTPS, so reaching the instance over plain `http` by another way (the container's port 8080 directly, a LAN address) loses the session and signing in never sticks. Set `SESSION_SECURE_COOKIE=false` in `.env` for such a local setup only, never in production.

The application sends `Referrer-Policy: same-origin` with every answer. Leave it as it is: Sanctum recognizes the web front (the requests that get a session) by the `Origin` or `Referer` header, so a proxy that overrides the policy with `no-referrer` makes the front's own same-origin `GET` calls (such as `/api/v1/me`) look like those of a stranger, and the signed-in pages stop working.

### Trust model

Everything the wizard does is behind the setup token: whoever holds it is trusted like the server administrator. In particular the checks connect to the addresses typed in the forms (database, SMTP, S3 endpoint, Documenso), so they can reach hosts on the internal network, by design: do not hand the token to somebody who should not be able to probe your network. Checks use short timeouts (5 seconds to connect) and never show passwords or API tokens in their messages.

### The steps

The wizard is one page with a progress bar and one AJAX endpoint per step. Each step **tests the real service before saving**, writes its values to `.env` immediately and is recorded in `storage/installer/progress.json`, so an interrupted installation resumes where it stopped (the token is asked again in a new browser session).

1. **Requirements**: the PHP version required by `composer.json`, the extensions (`pdo` with `pdo_pgsql` or `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `intl`, `gd` or `imagick`, `zip`, `curl`) and write access to `storage/`, `bootstrap/cache/` and `.env`.
2. **Database**: PostgreSQL 14+, MySQL 8.0+ or MariaDB 10.11+. The connection is tested with a temporary connection; a MariaDB server selected as "MySQL" (or the reverse) is refused, so is a database that holds anything but a previous run of this installer is refused (it must be empty, or have a `migrations` table whose entries are all this application's migrations), and the user must be able to create tables (the check creates, then drops, a table named `installer_probe_check`: it is refused if one already exists, and never touches it). An interrupted installation can be resumed on the same database.
3. **Application**: public URL, default language and timezone.
4. **Email**: SMTP settings and a test address. A 6-digit code is sent there and the step is only validated once it is typed back (valid 15 minutes, 5 attempts; the pending values are kept encrypted in the session).
5. **File storage**: the local disk, or an S3-compatible service (key, secret, region, bucket, endpoint, path-style). The check writes, reads and deletes a temporary file.
6. **Documenso** (optional, can be skipped): instance URL and API token. The check is one authenticated call to the Documenso API, `GET {url}/api/v2/envelope?page=1&perPage=1`, with the bare token in the `Authorization` header ([Documenso API reference](https://docs.documenso.com/docs/developers/api)).
7. **Administrator**: the name and the email address of the first administrator (`ADMIN_NAME` and `ADMIN_EMAIL` in `.env`). Nothing to test here: the account is created by the next step, once the tables exist. There is no password: the administrator signs in with a code sent to this address, so it must be one they can read.
8. **Installation**: runs the migrations, then the post-migration hooks, switches the instance to `APP_ENV=production` / `APP_DEBUG=false` (the web wizard always does, except for values the environment imposes), clears the configuration cache, writes the "installed" marker and deletes the token. The wizard then leads to `/login`.

Anything that has to happen once the tables exist but before the instance is marked installed plugs in as an `App\Installer\PostMigrationHook`, tagged `installer.post-migration`: hooks run after the migrations, must be idempotent (the installation can be run again after a failure) and abort it by throwing an `InstallationException`. The first one is `CreateAdministrator`, which creates the administrator account (or promotes the account that already has the address). Hooks run on the database being installed, whatever the application's default connection points at: in a web request, and in the interactive console installer, the configuration was loaded before the wizard wrote the database settings, so the finalizer makes its temporary connection (`installer_migrate`) the default one while the migrations and the hooks run, then puts everything back. A hook uses `DB` and its models without naming a connection.

### Values provided by the environment

With Docker, configuration usually arrives as real environment variables, which Laravel prefers over `.env`. The installer records the environment the process started with, at the very top of `bootstrap/app.php` (before `.env` is read): every variable in it is **imposed**, even when `.env` holds the same value. The wizard shows imposed values read-only with a "set by the environment" badge, probes them as is, and never writes them. Secrets are never sent back to the browser (an empty box means "unchanged"; to store an empty password on purpose, tick "No password" next to it), and a `.env` that cannot be written is reported with a clear message instead of being silently ignored.

`DB_URL` and `MAIL_URL` are honoured: when one is set (environment or `.env`), the corresponding step treats the connection as provided. Its fields are read-only and masked (a URL usually contains a password), and the checks and the migrations use exactly the configuration the application will use, URL included.

When every setting the installation needs is provided by the environment, nothing has to be written: the wizard does not require `.env` or `bootstrap/cache/` to be writable, which suits read-only containers: when `.env` cannot be written, finalizing such an instance writes nothing but the "installed" marker (set `APP_ENV` and `APP_DEBUG` in the environment too); when it can, the production defaults are written as usual. A `.env` the installer creates is readable by the owner and the group only (`0640`); an existing one keeps its permissions, which the installer never widens.

When `.env` does not exist, the installer creates it from `.env.example`, minus the development defaults: a file created by the installer (on the first web access or by the console command) starts with `APP_ENV=production`, `APP_DEBUG=false`, no database or SMTP credentials, no SMTP host, no sender address and no placeholder administrator, instead of the example's local services. If you copy `.env.example` yourself on a server, set these values yourself. For development, run `composer setup`, which copies the example first and leaves the environment alone.

### From the terminal

```bash
php artisan inventaire:install                 # interactive prompts, pre-filled from .env
php artisan inventaire:install --no-interaction \
    --db-driver=pgsql --db-host=127.0.0.1 --db-port=5432 --db-name=inventaire --db-user=inventaire \
    --app-url=https://inventory.example.com --locale=fr --timezone=Europe/Paris \
    --mail-host=smtp.example.com --mail-port=587 --mail-from-address=hello@example.com --mail-from-name="My Company" \
    --storage=local --admin-name="Jane Doe" --admin-email=jane@example.com
php artisan inventaire:install --from-env      # validate the current configuration and finalize
```

- In interactive mode every value is prompted and the mail step is proven with a code, like in the web wizard.
- In non-interactive mode (`--no-interaction`) the values come from the options (one per wizard field: `php artisan inventaire:install --help`), completed by what `.env` and the environment already provide; the first failing check stops the command with a non-zero exit code. The SMTP server is checked with an authenticated SMTP session, since nobody can read a code. `--skip-mail-test` skips that check (with a warning) and is refused when prompts are possible. Options end up in the shell history and the process list: for passwords, prefer `.env` or the environment.
- Switching to production mode is asked interactively (default yes, except on a `local` instance) and done by default in non-interactive mode; `--keep-environment` leaves `APP_ENV` and `APP_DEBUG` alone. `--db-no-password` and `--mail-no-password` store an empty password on purpose.
- `--from-env` asks nothing, never touches `APP_ENV` or `APP_DEBUG` (the developer path) and writes nothing to `.env` (it does not even need it to be writable): it validates the current configuration with the same live checks, then runs the migrations and finalizes. It is what `composer setup` runs, and what a container entrypoint can run. It needs `ADMIN_NAME` and `ADMIN_EMAIL` among the configuration, like the other required settings.

### Troubleshooting

- *I lost the setup token*: `php artisan inventaire:install-token`.
- *The wizard keeps showing finished steps*: the progress lives in `storage/installer/progress.json`; delete that file to start over (the configuration stays in `.env`).
- *I want to run the wizard again on an installed instance*: delete `storage/installer/installed.json`. This only re-enables the installer; it does not touch the database.

## Accounts and sign-in

Accounts have **no password**: an email address is all it takes. The `/login` page asks for the address, emails a 6-digit code, and typing the code back signs the person in; an address that has no account yet is asked for a name at that moment, so signing in and signing up are the same flow. The same steps are available to API clients as `/api/v1` endpoints:

| Endpoint                      | What it does                                                                                        |
|-------------------------------|-----------------------------------------------------------------------------------------------------|
| `POST /api/v1/auth/code`      | `email`: emails a code. The answer is the same whether the address has an account or not.            |
| `POST /api/v1/auth/verify`    | `email`, `code`, and `name` for a new account: signs in. `201` when the account was created.        |
| `POST /api/v1/auth/logout`    | Ends the session (`204`).                                                                           |
| `GET /api/v1/me`              | The signed-in user (`401` in JSON when nobody is).                                                  |
| `PATCH /api/v1/me`            | `name` and/or `locale`: changes them. A new `locale` also comes back in the `locale` cookie, like the language switcher's. |

The account's other endpoints are in [Workspaces](#workspaces) and [Your account: sessions, data and deletion](#your-account-sessions-data-and-deletion).

Every answer under `/api` is JSON, even for a client that sends no `Accept` header. Refusals of a code are `422` with `errors` and a machine-readable `code`: `login_code_wrong`, `login_code_expired`, `login_code_exhausted`, or `name_required` (right code, unknown address: send it again with `name`, the code is still valid).

- **Codes.** A code is valid for 10 minutes and 5 wrong guesses, and works once. Only a keyed hash (HMAC-SHA256 with `APP_KEY`) is stored, in the `login_codes` table, one row per address: asking again replaces the previous code. The mail is sent immediately, not queued (an instance without a queue worker still delivers it), in the language of the request, as plain text and simple HTML with nothing fetched from outside.
- **Limits** (`login_codes` in `config/auth.php`; `429` with `Retry-After`). Sending: 3 codes per address and IP address in 10 minutes, 10 per address (from all IP addresses together) in an hour, and 20 per IP address in 10 minutes. Typing codes back: 10 per minute and per IP address and address. An IPv6 client counts as its whole /64, since it can pick any address inside it. Behind a reverse proxy, set `TRUSTED_PROXIES` or every visitor shares one IP address.
- **Wrong codes over time.** Each code tolerates 5 wrong guesses, but asking for a new code does not give back the wrong codes an address already collected: after 10 of them within one period (`failures_per_email`: a fixed hour that opens with the first wrong code, then starts over), **code verification is locked for that address until the period is over**. No code is judged any more for it, **the right one included**, and the answer is `429` with `Retry-After` and `"code": "login_code_locked"`. Anybody who knows an address can lock it that way (that is the price of not letting a 6-digit code be guessed), so an administrator who finds their own address locked can wait for the end of the period or recover as described under "Administrators" below: `php artisan inventaire:make-admin` promotes another address, which signs in as usual. Only a guess that is judged and found wrong counts: the right code, a code that was already burnt or spent, and codes typed for an address that has no pending code cost nothing. The count is kept in the cache (`CACHE_STORE`, `database` by default) and updated with atomic increments, so parallel guesses cannot get around it; a cache store that cannot count (`null`) makes verification fail with a server error instead of silently switching the limit off, and emptying the cache gives the guesses back once.
- **No user enumeration.** Asking for a code does the same work and gives the same answer whether the address has an account or not, and wrong codes are answered the same too. Whether an address has an account is only revealed to somebody who holds a valid code for it.
- **Addresses** are stored and looked up in one canonical form: trimmed, lowercase, and the domain in ASCII (punycode: `gmäil.com` becomes `xn--gmil-moa.com`). An accent or a non-Latin letter before the `@` is refused. MySQL's and MariaDB's default collations ignore accents, so without this `john@gmäil.com` would be `john@gmail.com` for them and the owner of a lookalike domain could receive the code of somebody else's account; the migration therefore also gives `users.email` and `login_codes.email` a binary collation (`utf8mb4_bin`) on those engines, so that the database itself compares addresses exactly (PostgreSQL always does). The migration that makes users passwordless brings the addresses already stored to that form, and stops, before changing anything, if two accounts would end up with the same address: merge or delete one of them, then run it again. Rolling it back restores an empty `password` column (the hashes are gone).
- **Staying signed in.** Signing in is remembered for 30 days by the "remember me" cookie (`remember` of the `web` guard in `config/auth.php`). The session itself keeps `SESSION_LIFETIME`, since visitors who are not signed in have sessions too. Signing out ends the session and forgets the cookie of *this* device only: the other devices of the account stay signed in (they share the account's remember token, which signing out leaves alone). To end the other devices' sessions at once, see [Signing out everywhere](#signing-out-everywhere).
- **Language.** `users.locale` is the language of the account. The language of a request is decided before the session starts (see [Translations](#translations)), so the account's language travels in the `locale` cookie: signing in sets the cookie from `users.locale` (an account without one takes the language in use), and the language switcher updates the cookie and `users.locale` together.
- **Stateful requests (Sanctum).** The web front calls the API with its session cookie and CSRF token. Laravel Sanctum treats a request as coming from the front when its `Referer` or `Origin` host is listed in `SANCTUM_STATEFUL_DOMAINS`; by default that is the usual development hosts and the host (with the port) of `APP_URL`, so an instance works when it is reached on its public address. If it is also reached on another host (a second domain, a LAN address, another port), signing in from there answers `403` with `"code": "not_stateful"`: list every such host, with its port when it is not the default one, in `.env`:

  ```dotenv
  SANCTUM_STATEFUL_DOMAINS=inventory.example.com,inventory.example.net:8443
  ```

  The variable replaces the default list, so repeat the host of `APP_URL` in it. The sign-in endpoints refuse any other caller on purpose: the session and the CSRF check are what keep a forged form on another site from signing a visitor into somebody else's account.
- **Administrators.** `users.is_admin` marks the people who administer the instance. The installer creates the first one; to add one later, or to recover when nobody can sign in as one (an address locked by too many wrong codes included: see above), run `php artisan inventaire:make-admin jane@example.com --name="Jane Doe"` (with Docker: `docker compose exec app php artisan inventaire:make-admin ...`). It creates the account or promotes the existing one (keeping its name), and can be run again safely.

## Workspaces

Inventories live in workspaces. Every account belongs to at least one, in which it holds a role, and works in one at a time (its *current* workspace).

- **Tables.** `workspaces` (`id` for the database, a unique `ulid` for the outside world: URLs, API, never the numeric key), `workspace_user` (the membership: `role`, one row per user and workspace, deleted with either side) and `users.current_workspace_id` (forgotten if that workspace is deleted).
- **Roles** (`App\Workspaces\WorkspaceRole`): `owner`, `editor`, `viewer`. The abilities are asked through `canView()`, `canEdit()` (content, from lot 4 on) and `canManage()` (the workspace itself: rename, delete, members), never by comparing roles. `App\Policies\WorkspacePolicy` maps them to actions: any member can see a workspace, only owners change, delete or manage it. Somebody who does not belong to a workspace is answered `404`, as if it did not exist; a member without the right gets `403`, with a message that names what was refused. The `role` column is a free string, so a row may hold a word this version does not know (a rollback, an edit by hand): `App\Workspaces\StoredRole` reads it as `viewer`, the role with the fewest rights, and logs a warning once per value and process, instead of failing the pages that list workspaces.
- **A personal workspace for everyone.** An account always has one: it is created, named "Personal space" in the language of the account, in the transaction that creates the account (`Accounts::register()`: sign-up, the installer's administrator, `inventaire:make-admin`), when an existing account without any is promoted to administrator, and at sign-in for an account that lost its last one. The migration `give_every_user_a_workspace` gives one to every account that existed before workspaces did; it can be run again safely.
- **The current workspace** is `users.current_workspace_id`; when it is empty, or no longer a workspace the user belongs to, it is the first one they joined. Creating a workspace makes it current. Deleting the current one moves the user to another. `Workspaces::current()` heals an account that belongs to none (the rules prevent it, but a row changed by hand or a restore can break them): it gives it its personal workspace, so that nobody is ever stuck without somewhere to work.
- **The last workspace** of an account cannot be deleted (`409`, `"code": "last_workspace"`). Deleting a workspace that other people belong to gives a personal workspace to those who had no other one.
- **Limits.** An account cannot belong to more than `workspaces.max_per_user` workspaces (50; `WORKSPACES_MAX_PER_USER`): beyond it, creating one is a `409` with `"code": "workspace_limit_reached"` and the `limit`. Creating is also throttled per user to `workspaces.create_per_hour` (20 an hour; `WORKSPACES_CREATE_PER_HOUR`), then `429`. Both are in `config/workspaces.php`.
- **Concurrency.** Whatever removes memberships or workspaces runs in one transaction under row locks taken in a global order, which anything that adds members (the invitations of lot 9) must follow too: see `App\Workspaces\Locks`. A transaction the database picks as the loser of a deadlock is retried (`App\Database\DeadlockRetries`).

| Endpoint                            | What it does                                                                                  |
|-------------------------------------|-----------------------------------------------------------------------------------------------|
| `GET /api/v1/workspaces`            | Your workspaces, oldest membership first: `id` (the ULID), `name`, `role`, `is_current`, `created_at`. |
| `POST /api/v1/workspaces`           | `name` (required, trimmed, at most 100 characters): creates a workspace you own and work in (`201`). |
| `PATCH /api/v1/workspaces/{id}`     | `name`: renames it (owners only).                                                              |
| `DELETE /api/v1/workspaces/{id}`    | Deletes it, and everything in it (owners only; `204`).                                         |
| `PUT /api/v1/me/current-workspace`  | `workspace` (a ULID): the workspace to work in. One you do not belong to is refused like one that does not exist (`422`). |

Answers are in the `data` envelope. Errors have a `message` in the language of the request and, for conflicts, a machine-readable `code`.

In the interface, the header has a switcher (before the account menu) and `/app/workspaces` lists the workspaces to create, rename in place, delete (with a confirmation in the row) and choose between. The switcher and the page share one Alpine store, so a change shows in the header without a reload.

## Your account: sessions, data and deletion

`/app/account` ("My account") holds the profile (name, language), the security of the sessions, the download of the data, and the deletion of the account.

### Signing out everywhere

`POST /api/v1/me/sign-out-everywhere` (the web front only) ends the sessions of every device but the current one and revokes the account's API tokens (a lost device may hold one; they are not tied to a session). It renews the account's remember token and signs the current device in again with a fresh session, a cookie for the new token and the new fingerprint (the framework stores it in the session as the device signs in, before any other request).

How it works: the framework ties a session and a "remember me" cookie to a "password hash" of the account. Accounts have no password, so `User::getAuthPassword()` derives that fingerprint from the remember token (an HMAC with `APP_KEY`, empty for an account without a token). The cookie carries the token itself, so a copied cookie with an old token no longer finds the account; a session stores the fingerprint when it is opened, and `AuthenticateSession` signs it out as soon as it differs. That check runs on the pages of the signed-in space only (the `auth` routes of `routes/web.php`: `/app/*`), with `App\Http\Middleware\AuthenticateSession` (the framework's, which waits for the installation to be over so that the install wizard never looks anybody up), and on the API with `App\Http\Middleware\AuthenticateApiSession` (Sanctum's own middleware for the requests of the web front, replaced in `config/sanctum.php`). The public pages never look the visitor up for it: a visitor with a revoked session who opens the home page simply sees it, and is signed out when they enter `/app`. Both refuse a signed-in session that carries no fingerprint (`App\Auth\SessionFingerprint`) instead of giving it the current one as the framework does, which would let a session that predates the fingerprints outlive any sign-out everywhere; a request signed in by a valid "remember me" cookie is not concerned, because every sign-in leaves its session with the fingerprint, on any route: the guard fires `Login` for a sign-in by cookie as well, and `App\Auth\StoreSessionFingerprint` listens to it, so a visitor whose session expired and whose cookie signs them in on a public page (the home page, the login page) keeps it for their next click into `/app`, while a cookie from before a sign-out everywhere (its token was renewed) signs nobody in and gets nothing. Signing out of one device keeps the token, so the others stay signed in. Every account gets a token when it is created, and the migration `give_every_user_a_remember_token` gives one to those that have none.

Because the fingerprint stands where the framework expects a password hash, a few framework helpers misbehave on this application and must not be used: `logout()` (renews the shared token, so it signs the account out of every device), `logoutOtherDevices()` and the `password.confirm` middleware. Sign out of this device with `logoutCurrentDevice()`. `tests/Unit/AuthenticationGuardrailsTest.php` fails if `app/`, `routes/` or `bootstrap/` use them.

When updating an instance that did not have this: a session opened before has no fingerprint, and is refused (whatever keeps the sessions: database, files, the cache or Redis), so **everybody signs in again once**, with a code, at the update. A "remember me" cookie issued before carries the previous constant, empty fingerprint and is refused as well.

### Your data (GDPR)

`GET /api/v1/me/export` (throttled: `account_export` in `config/auth.php`) downloads `inventaire-export-YYYY-MM-DD.json`: the profile, the workspaces with the role in each, the sessions the account has in the database (`sessions`: `ip_address`, `user_agent`, `last_activity`; empty when sessions are kept anywhere else) and the metadata of its API tokens (`tokens`: `name`, `abilities`, `last_used_at`, `created_at`). The document is versioned (`format`, `version`) and each workspace already carries an empty `inventories` list, which the inventories of lot 4 will fill without changing the structure. Nothing secret is in it (no session id, no token, no hash), and nothing about other people.

### Deleting the account

Deleting cannot be undone, so it takes a code mailed to the address of the account, in two steps (both session actions, for the web front only):

1. `POST /api/v1/me/deletion-code` mails a 6-digit code, in the language of the account (plain text and simple HTML, like the sign-in code's mail). It is a code of its own: it lives in the `account_deletion_codes` table (one row per user, a keyed hash derived from `APP_KEY`, the purpose and the user, valid 10 minutes for 5 wrong guesses), never replaces or spends a sign-in code, and cannot be used for one. Sending is throttled per user (3 per 10 minutes, 6 per hour).
2. `DELETE /api/v1/me` with `{ "code": "123456" }`, throttled to 5 per minute. A code that is not accepted is a `422` with `"code"` set to `deletion_code_wrong`, `deletion_code_expired` (never asked, replaced or expired) or `deletion_code_exhausted`.

The limits are in `account_deletion` in `config/auth.php`. There is one way to delete an account, whoever asks (this page, an administrator later, `tinker`): `User::delete()`. It runs in one transaction (if anything fails, nothing is deleted; a deadlock is retried) and the model's `deleting` event calls `AccountDeletion::release()`, which judges the rules and removes what goes with the account under the locks described in [Workspaces](#workspaces) (the sign-in code of the address, then the administrators and the account in id order, then its workspaces), so that two administrators deleting themselves at once, or two co-owners, are judged one after the other, and a deletion and a sign-in of the same address cannot deadlock. Deleting an account that is already gone (a stale copy of it, or somebody else deleted it first) raises a `ModelNotFoundException` instead of reporting a deletion that deleted nothing; the API answers `404` with `"code": "account_not_found"` and ends the session of that device, as after a deletion. Deleting rows with a query raises no model event and is not a way to delete an account. It removes:

- the workspaces that nobody else belongs to, with everything in them, each one through its own `Workspace::delete()` so that its model events fire (what lot 4 stores for a workspace, files on disk or S3, is purged there);
- the account's place in the workspaces shared with other people (it just leaves them);
- its rows in the `sessions` table (when sessions are kept in the database), the sign-in code waiting for its address, its deletion code and its API tokens, then the user.

The device is signed out at the end. Two things stop a deletion, with a `409` and a `code` that the card on the account page explains (it asks as soon as the code is requested, and again when it is confirmed; the code is kept, so nothing has to be asked again once the obstacle is gone):

- `sole_owner_of_shared_workspaces`: the account is the only owner of workspaces that other people belong to. The body lists them (`workspaces`: `id` and `name`). Ownership has to be handed over first; the screens to do it come with member management (lot 9).
- `last_administrator`: the account is the last administrator of the instance. Make somebody else one first with `php artisan inventaire:make-admin`.

## Upgrading an instance

After every update of the code, bring the instance up to date:

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan inventaire:upgrade --optimize
```

An instance installed before sign-in existed has no administrator and the upgrade does not create one (the installer's hooks only run during the installation): create one with `php artisan inventaire:make-admin you@example.com --name="Your Name"`. The same upgrade removes the `password` column and the `password_reset_tokens` table, which accounts no longer use.

The upgrade that brings workspaces gives every existing account a personal workspace and a remember token (idempotent migrations). It also leaves the sessions opened before without a fingerprint, which are refused, and a "remember me" cookie issued before is refused as well: **everybody signs in again once**, with a code (see [Signing out everywhere](#signing-out-everywhere)).

`inventaire:upgrade` reloads the configuration (so an edited `.env` is used), applies the pending migrations under a file lock (a second upgrade running at the same time waits for it, up to 10 minutes) and clears the route, view and event caches. With `--optimize` it rebuilds them afterwards; it can be run as often as you like, and with Docker the update is automatic (see below).

## Self-hosting with Docker

The image bundles PHP, the web server (FrankenPHP) and the built front end: you need Docker with Compose, not PHP or Node. `docker/compose.yaml` is a ready-to-use example with the application, a queue worker, a scheduler and PostgreSQL.

```bash
cd docker
cp .env.example .env        # set DB_PASSWORD at least
docker compose up -d --build
```

The application listens on <http://localhost:8080> (`APP_PORT` in `.env` changes the port). Everything below is run from the `docker/` folder.

### Two ways to configure it

- **Wizard.** Set `DB_PASSWORD` only. Open the application: it redirects to the install wizard, which asks for the rest. Its one-time setup token is printed by `docker compose exec app php artisan inventaire:install-token`. The database settings come from the compose file, so the wizard shows them read-only.
- **Environment.** Put the whole configuration in `.env` (the full list is in `docker/.env.example`: URL, language, timezone, SMTP, file storage, the first administrator `ADMIN_NAME` and `ADMIN_EMAIL`...). Every variable set there reaches the container as a real environment variable and shows read-only in the wizard ("set by the environment"). When nothing is left to ask, the instance installs itself the first time the `app` container starts, with the same live checks as the wizard (database, SMTP, storage). If one fails the container stops with the reason (`docker compose logs app`) and Compose retries until you fix it.

### What is kept where

The `storage` volume is `/app/storage`: it holds the configuration the installer generates (`.env`, with `APP_KEY` and the secrets entered in the wizard), the uploaded files, the installer state (including the "installed" marker) and the logs. **Back it up together with the database, and keep it private**: whoever reads it can read your secrets, and an instance whose database survives but whose `APP_KEY` is lost cannot decrypt what it stored. Do not delete the volume unless you mean to erase the instance. If you replace it with a host folder, make it writable by user ID 1000, the unprivileged user every process of the container runs as.

`APP_ENVIRONMENT_FILE` (default `/app/storage/.env`) tells Laravel and the installer which file holds that configuration. It must be an absolute path; Laravel prefers a file named like it followed by `.` and `APP_ENV` (`.env.production`) when one exists in the same folder, so keep none there.

`APP_ENV`, `APP_DEBUG` and `LOG_CHANNEL` are environment variables of the image (production mode, errors on stderr), not entries of that file: a real environment variable always wins over it. To change them, set them in `docker/.env`, not in `/app/storage/.env`.

### Updating

```bash
git pull
docker compose up -d --build
```

When the `app` container starts on an installed instance it runs `inventaire:upgrade --optimize` before serving: the migrations are applied and the caches rebuilt. If the update fails (a migration, for instance) the container stops with a non-zero status instead of serving a half-updated instance; read `docker compose logs app`. Compose restarts the worker and the scheduler whenever it recreates or restarts `app` (`restart: true` in their `depends_on`), so they run the new code once the application is healthy. Back up before updating.

To run the update by hand, without recreating the containers:

```bash
docker compose exec app php artisan inventaire:upgrade --optimize
docker compose restart worker scheduler
```

### Another database server

`docker/compose.yaml` bundles PostgreSQL. To use a server you already have (PostgreSQL, MySQL or MariaDB), delete the `db` service, the `depends_on` entry of `app` that waits for it, and the `DB_*` lines of the shared environment. The wizard's database step is then editable; or put `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `docker/.env` to install without the wizard. Commented examples of bundled MySQL and MariaDB services are at the end of the file.

### Reverse proxy and HTTPS

The container speaks plain HTTP on port 8080. Terminate TLS in a reverse proxy in front of it, set `APP_URL` to the public `https://` address, and set `TRUSTED_PROXIES` in `.env` to the proxy's address or CIDR range (see "Behind a reverse proxy"): otherwise every visitor looks like the proxy and the setup token throttle counts them together. Opening the container directly over plain `http` (for instance `http://localhost:8080`) with an `https://` `APP_URL` loses the session: see `SESSION_SECURE_COOKIE` under "Behind a reverse proxy". Keep the proxy from overriding the `Referrer-Policy: same-origin` header the application sends (see "Behind a reverse proxy").

### Day-to-day commands

```bash
docker compose exec app php artisan inventaire:install-token
docker compose exec app php artisan inventaire:make-admin jane@example.com --name="Jane Doe"
docker compose run --rm app php artisan tinker           # any other command, in a throw-away container
docker compose logs -f app                               # application errors go to stderr
```

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

The tests never touch the real `.env`, installer state, cached configuration or storage: `phpunit.xml` points `INSTALLER_STATE_PATH`, `INSTALLER_ENV_FILE`, `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, `VIEW_COMPILED_PATH` and `CACHE_FILE_PATH` at a throw-away folder below `storage/framework/testing/`, emptied around every test (`tests/Support/InstallerSandbox.php`). Every test boots in the "installed" state, so tests that predate the installer are unaffected; a test that needs a fresh instance calls `$this->bootInstaller(installed: false)`. Run the suite sequentially: the sandbox folder is shared. (`composer test` itself starts with `php artisan config:clear`, which does clear the real cached configuration.)

Switching `actingAs()` from one user to another inside a test needs `$this->flushSession()` in between: the session fingerprint of the first user is still in the store.

Safety net: `tests/TestCase.php` refuses to boot (before any migration) unless the configured database name ends with `_test`. A stale cached config (`php artisan config:clear` fixes it) or a `DB_DATABASE` exported in your shell can therefore never wipe the development database.

## Project structure

```text
app/
  Auth/             Sign-in codes (LoginCodes), account creation (Accounts), session fingerprint check (SessionFingerprint), account deletion (AccountDeletion, AccountDeletionCodes) and export (AccountExport)
  Console/Commands/ inventaire:install (console wizard), inventaire:install-token, inventaire:install-status, inventaire:upgrade, inventaire:make-admin
  Http/
    Controllers/    Invokable or resourceful controllers (Install/ holds the wizard endpoints, Api/V1/ the JSON API)
    Middleware/     SetLocale (global): applies the language chosen by LocaleResolver;
                    RedirectIfNotInstalled (global), AbortIfInstalled, AuthorizeSetupToken: the install gate;
                    RequireStatefulRequest: session endpoints are for the web front only;
                    AuthenticateSession (web, signed-in space) and AuthenticateApiSession (API): sign out the sessions of an account that signed out everywhere
    Requests/       Form requests (validation)
    Resources/      JSON resources of the API (UserResource, WorkspaceResource)
    Responses/      ApiError: the conflicts (409) and coded validation failures of the API
  Installer/        Installation state, .env writer, setup token, probes, finalizer, migrator, post-migration hooks (see "Installing an instance")
  Mail/             LoginCodeMail, AccountDeletionCodeMail
  Models/           User, LoginCode, AccountDeletionCode, Workspace, WorkspaceMember
  Policies/         WorkspacePolicy: who may do what with a workspace
  Providers/        InstallerServiceProvider: pre-install runtime and application key; AppServiceProvider: the rate limiters (sign-in, account, workspace creation)
  Services/         LocaleResolver: which language a request is answered in; OneTimeCode: 6-digit codes and their hash
  View/Components/  Blade component classes (ClientMessages, WorkspaceSwitcher)
  Workspaces/       Workspaces (the rules: create, delete, current workspace), Locks (the row locks every removal follows), WorkspaceRole, StoredRole (reads a stored role safely), Membership
config/             Laravel configuration (app.php lists the supported locales)
lang/               fr.json (UI strings) and fr/ (framework messages)
resources/
  css/app.css       Tabler, the self-hosted Inter font and project tokens
  js/
    app.js          Entry point: Tabler, Alpine, stores, components
    lib/            http.js (the one HTTP helper), i18n.js, notify.js, page.js
    stores/         Alpine stores (toasts, workspaces)
    components/     Alpine components (toasts, locale switcher, install wizard, sign-in page, account menu, workspace switcher, workspaces page, the cards of the account page)
  views/
    layouts/        app.blade.php, the Tabler page layout
    components/     Blade components (<x-toasts />, <x-locale-switcher />, <x-account-menu />, <x-workspace-switcher />)
    auth/, app/     The sign-in page and the signed-in user's pages (space, workspaces, account)
routes/web.php      Web routes
routes/api.php      JSON API, versioned in the path (/api/v1)
tests/
  Feature/          Pest feature tests (HTTP, database)
  Unit/             Pest unit tests
  js/               Vitest tests
compose.dev.yaml    Development services
Dockerfile          Self-hosting image (see "Self-hosting with Docker")
docker/             Image entrypoint, Caddyfile and php.ini; compose.yaml and .env.example for self-hosters
```

## Conventions

- **One HTTP helper.** Scripts never call `fetch` directly: they use `http` from `resources/js/lib/http.js` (see below).
- **No display text in JavaScript.** Strings that scripts need are translated on the server and embedded by `<x-client-messages />`; scripts read them with `t('key')`.
- **Alpine, CSP build.** The app uses `@alpinejs/csp`, so a strict Content-Security-Policy without `'unsafe-eval'` can be enforced (the header itself arrives in a later lot). In practice:
  - every component is registered in `resources/js/app.js` with `Alpine.data('name', factory)` and referenced by name in the markup (`x-data="toasts"`), never with an inline object;
  - expressions in templates stay simple: property access, method calls with arguments, comparisons, `!`, `&&`/`||`, ternaries and string `+`. Arrow functions, template literals, `;` statements and Blade values interpolated inside an expression are not allowed: put that logic in a component method;
  - a component gets its values from `data-*` attributes (`data-url` on the root, `data-locale` on an item), read through `$root` or `event.currentTarget`, and translated texts are rendered by Blade as plain attributes;
  - `tests/js/templates/alpine-csp.test.js` scans every Blade template for forbidden syntax and mounts the toast markup with the real CSP build; `tests/js/templates/pages.test.js` does the same for the workspace switcher, the workspaces page and the account page, and drives them like a person would. Alpine applies what a click changed within a frame or two: wait with `requestAnimationFrame`, not only with `nextTick`.
- **Icons.** Use `<x-tabler-*>` components and do not pass a `class` attribute: the SVG files already have one, which would render the attribute twice. Size and space icons from a parent element.
- **Documentation.** Every class and public method carries a PHPDoc/JSDoc block that explains the why, not only the what.
- **Language.** Code, identifiers, comments and commit messages are in English.

## HTTP helper

`http.get/post/put/patch/delete` is the contract every action relies on. It sends JSON with `Accept`, `Accept-Language`, `X-Requested-With` and the CSRF token (`X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie, falling back to the `csrf-token` meta tag, so rotation after login or logout is followed). It resolves the parsed JSON, `null` for `204`, or a `Blob`/text with `{ responseType: 'blob' | 'text' }`; a `FormData` body is sent as is. `{ timeout }` (default 30 s, covering the body too) and `{ signal }` can be combined.

Failures throw an `HttpError` (`status`, `message`, `errors`, `code` from `body.code`, `retryAfter`, `kind`, `reported`). Raw response messages are never shown, except the server-translated validation messages of a `422`; everything else is looked up by key in the strings embedded by `<x-client-messages />`.

| Situation              | Central handling (skip all with `{ silent: true }`)                                              |
|------------------------|--------------------------------------------------------------------------------------------------|
| `401`                  | Toast, then redirect to the `login-url` meta tag (every page has it: the `login` route exists), else reload |
| `403`, `404`           | Toast                                                                                            |
| `419`                  | Refresh the token (`csrf-refresh-url` meta tag) and replay once; only a second failure toasts and reloads |
| `422`, other `4xx`     | Nothing: the caller handles it (`errors`, `code`)                                                |
| `429`                  | Toast; `retryAfter` holds the `Retry-After` delay in seconds                                     |
| `5xx`                  | Toast                                                                                            |
| Network, timeout       | Toast (`HttpError.status === 0`, `kind` is `network` or `timeout`)                               |
| `2xx` that is not JSON | Toast and `HttpError` with `kind === 'unexpected_response'`                                      |

A page that is open to visitors (the sign-in page, the user menu's sign-out) must pass `{ silent: true }` and show the failures itself: the central handling would answer a `401` or a `403` by redirecting to the very page the visitor is on.

A request cancelled through the caller's `signal` rejects with the raw `AbortError` and no toast. `delete` takes no body by convention; the few deletions that ask for a confirmation pass it as `http.delete(url, { data: { code } })`. `t('key', { name: value })` fills the `:name` placeholders of a message.

## Translations

French is the default language and English the fallback. Interface strings are written in English in the code with `__()` and translated in `lang/fr.json`; framework messages (validation, authentication, pagination, passwords) come from [laravel-lang](https://laravel-lang.com).

The language of a request is chosen by `App\Services\LocaleResolver`, trying in order: the one-year `locale` cookie written by `PUT /locale` (and by signing in, from the account's `users.locale`), the best `Accept-Language` match, then the default. The `SetLocale` middleware is global and runs first, so 404 pages, expired-session errors and the future API are localized too, and responses carry `Content-Language` and `Vary: Accept-Language, Cookie`. The `locale` cookie is excluded from encryption because its value is validated against the supported list on every read. The account's language is not a source of its own because the resolver runs before the session and the authentication, which a 404 page or the install wizard should not have to start: see [Accounts and sign-in](#accounts-and-sign-in).

To add a language, for example Spanish:

1. Add `es` to `locale_names` in `config/app.php` (its keys are the supported locales).
2. Run `php artisan lang:add es` for the framework messages and `lang/es.json`, then translate the project strings in it.

The language switcher and the `PUT /locale` endpoint pick the new locale up from the configuration.

## License

Released under the [GNU Affero General Public License v3.0](../LICENSE) (AGPL-3.0). If you run a modified version as a network service, you must offer its source code to your users (section 13): set `APP_REPOSITORY_URL` in `.env` to where your fork's source is published. The page footer links to that URL, and to its `LICENSE` file, instead of the upstream repository.
