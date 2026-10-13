# inventaire.cloud — web application

The web application behind [inventaire.cloud](https://inventaire.cloud), a free and simple inventory tool for professionals and individuals.

It is built **API-first**: the pages are classic server-rendered navigation, but every action goes through one HTTP helper so the same JSON API (`/api/v1`, coming in a later lot) can serve the web front, the native apps and third-party tools.

## Stack

| Layer      | Choice                                                                                  |
|------------|-----------------------------------------------------------------------------------------|
| Backend    | Laravel 13, PHP 8.3+                                                                    |
| Database   | PostgreSQL or MySQL, never engine-specific SQL (SQLite is not supported)                |
| Front end  | Blade + [Tabler](https://tabler.io) (Bootstrap 5) + Alpine.js, bundled by Vite          |
| Icons      | [`secondnetwork/blade-tabler-icons`](https://github.com/secondnetwork/blade-tabler-icons) |
| Tests      | Pest (PHP), Vitest + jsdom (JS)                                                         |
| Quality    | Pint, Larastan (level 8), ESLint, Prettier                                              |

Privacy by design: no CDN, no Google Fonts, no external request from the browser. The Inter font and every asset are served by the application itself.

## Requirements

- PHP 8.3 or newer (developed on 8.5) with the `pdo_pgsql` and `pdo_mysql` extensions
- [Composer](https://getcomposer.org) 2
- Node.js 20.19+ / 22.12+ and npm
- [Docker](https://www.docker.com) with Compose, for the development database and mail services

## Development setup

Run every command from this `web/` directory.

1. **Start the services** (PostgreSQL, MySQL, Mailpit) and wait until they are healthy:

   ```bash
   docker compose -f compose.dev.yaml up -d --wait
   ```

   | Service    | Host port                          | Credentials                                  |
   |------------|------------------------------------|----------------------------------------------|
   | PostgreSQL | `54329`                            | database `inventaire`, user `inventaire`, password `secret` |
   | MySQL      | `33069`                            | same as above                                |
   | Mailpit    | `1025` (SMTP), `8025` (web UI)     | none                                         |

   Each database also has an `inventaire_test` schema reserved for the test suite. These scripts only run when a volume is first created; use `docker compose -f compose.dev.yaml down -v` to start from scratch.

2. **Install and configure** the application. `composer setup` installs the dependencies, creates `.env` from `.env.example`, generates the application key, runs the migrations and builds the assets:

   ```bash
   composer setup
   ```

   `.env.example` points at the PostgreSQL service. To develop on MySQL instead, uncomment the MySQL block in `.env` (and comment the PostgreSQL one), then run `php artisan migrate:fresh`.

3. **Run the application.** `composer dev` starts the PHP server, the queue worker, the log tail and the Vite dev server together:

   ```bash
   composer dev
   ```

   The app is then available on <http://localhost:8000> and the mail inbox on <http://localhost:8025>.

## Quality commands

| Command                | What it does                                                         |
|------------------------|----------------------------------------------------------------------|
| `composer lint`        | Checks PHP code style with Pint (`pint --test`)                      |
| `composer format`      | Fixes PHP code style with Pint                                       |
| `composer analyse`     | Runs Larastan at level 8 on `app`, `config`, `routes`, `database`    |
| `composer test`        | Runs the Pest suite on PostgreSQL, then on MySQL (stops at the first failure) |
| `composer test:pgsql`  | Runs the suite on the PostgreSQL service only                        |
| `composer test:mysql`  | Runs the suite on the MySQL service only                             |
| `npm run lint`         | ESLint, then Prettier in check mode (JS, CSS, JSON)                  |
| `npm run format`       | Fixes JS, CSS and JSON files                                         |
| `npm test`             | Runs the Vitest suite                                                |
| `npm run build`        | Builds the production assets into `public/build`                     |

All PHP files under `app`, `config`, `routes`, `database` and `tests` declare `strict_types`, and Larastan runs without a baseline: fix new findings instead of silencing them.

## Testing on PostgreSQL and MySQL

The application has to behave the same on both engines, and SQLite is deliberately not supported. The database tests (`RefreshDatabase`) run the real migrations, so running the suite on each engine catches engine-specific SQL early. The services must be running (`docker compose -f compose.dev.yaml up -d --wait`):

```bash
composer test         # PostgreSQL, then MySQL; stops at the first failure
composer test:pgsql   # PostgreSQL only
composer test:mysql   # MySQL only
```

These scripts point `DB_*` at the compose services and use the dedicated `inventaire_test` schema. `phpunit.xml` targets the PostgreSQL test schema too, so a plain `php artisan test` is safe as well.

Safety net: `tests/TestCase.php` refuses to boot (before any migration) unless the configured database name ends with `_test`. A stale cached config (`php artisan config:clear` fixes it) or a `DB_DATABASE` exported in your shell can therefore never wipe the development database.

## Project structure

```text
app/
  Http/
    Controllers/    Invokable or resourceful controllers
    Middleware/     SetLocale resolves the request language
    Requests/       Form requests (validation)
  View/Components/  Blade component classes (ClientMessages)
config/             Laravel configuration (app.php lists the supported locales)
lang/               fr.json (UI strings) and fr/ (framework messages)
resources/
  css/app.css       Tabler, the self-hosted Inter font and project tokens
  js/
    app.js          Entry point: Tabler, Alpine, stores, components
    lib/            http.js (the one HTTP helper), i18n.js, notify.js, page.js
    stores/         Alpine stores (toasts)
    components/     Alpine components (locale switcher)
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

- **One HTTP helper.** Scripts never call `fetch` directly: they use `http` from `resources/js/lib/http.js`, which adds the CSRF token, locale and JSON headers and reports expired sessions and server errors with a toast.
- **No display text in JavaScript.** Strings that scripts need are translated on the server and embedded by `<x-client-messages />`; scripts read them with `t('key')`.
- **Icons.** Use `<x-tabler-*>` components and do not pass a `class` attribute: the SVG files already have one, which would render the attribute twice. Size and space icons from a parent element.
- **Documentation.** Every class and public method carries a PHPDoc/JSDoc block that explains the why, not only the what.
- **Language.** Code, identifiers, comments and commit messages are in English.

## Translations

French is the default language and English the fallback. Interface strings are written in English in the code with `__()` and translated in `lang/fr.json`; framework messages (validation, authentication, pagination, passwords) come from [laravel-lang](https://laravel-lang.com).

To add a language, for example Spanish:

1. Add `es` to `supported_locales` and `locale_names` in `config/app.php`.
2. Run `php artisan lang:add es` for the framework messages and `lang/es.json`, then translate the project strings in it.

The language switcher and the `PUT /locale` endpoint pick the new locale up from the configuration.

## License

Released under the [GNU Affero General Public License v3.0](../LICENSE) (AGPL-3.0). If you run a modified version as a network service, you must offer its source code to your users.
