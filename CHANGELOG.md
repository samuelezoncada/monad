# Changelog

Notable changes to Monad. This project follows [Semantic Versioning](https://semver.org/);
while the major version is 0, breaking changes may land in minor releases and are
called out below.

## [0.1.1] - 2026-10-26

Packaging release; no runtime changes.

### Changed

- Package archives exclude `.github/`. Tests were already excluded via
  `.gitattributes`, so the Composer dist now contains only engine, CLI, docs and
  package metadata.

## [0.1.0] - 2026-10-26

First tagged release.

### Added

- Single-file engine (`monad.php`): DI container, router with onion middleware,
  auto-escaping view layer, PDO/SQLite wrapper, sessions, flash messages, CSRF,
  authentication, filesystem cache, schema migrations, SSE and HTMX helpers, and a
  dependency-free test runner.
- Request body parsing: `$app->request->input()` and `->json()` expose form,
  URL-encoded (including `PUT`/`PATCH`/`DELETE`) and JSON bodies; `verifyCsrf`
  accepts the token from all of them.
- Automatic `OPTIONS` responses (`204` + `Allow`) and `HEAD`/`OPTIONS` in `Allow`
  headers. `Response::html()` emits safe markup verbatim; `text()` is plain text.
- Composer support: `classmap` autoload plus `Monad\App::boot($root)` for apps that
  never `require` the engine directly, and `vendor/bin/monad`.
- `HEAD` requests match `GET` routes and discard the body; an explicit `HEAD` route
  takes precedence.
- `$app->setRoot()` loads the app's `vendor/autoload.php` once the root is known, so
  an entry point outside the app root still sees the app's dependencies.
- CI installs the package into a separate fixture app and exercises its HTTP entry
  point, migrations and packaged CLI.
- The `monad` CLI loads `<app root>/bootstrap.php` before dispatch, so app commands
  registered there work through `vendor/bin/monad` as well as HTTP.

### Changed

- Configuration moved from `monad.ini` to `config.php`, which returns an array. The
  file is optional, is re-executed whenever the container resets, and is never
  exported to `getenv()`/`$_ENV`.
- The cache directory defaults to `<app root>/cache` instead of a system temp path.
- The repository is the library only. `public/index.php`, `config.php`, templates
  and migrations belong to the app, above the `public/` docroot.

### Fixed

- A template or layout that throws no longer leaks its partial output buffer into
  the error page.
- `flash->has()` recognises a stored `null`; auth honours a custom `auth.id` column.
- `Allow` headers and automatic `OPTIONS` handling now agree with the routes.

### Notes

- Requires PHP 8.0+ with `pdo_sqlite`; sessions additionally need `ext-session`.
- `debug` defaults to `true` when no `config.php` is present. Set it to `false`
  before deploying.
- Migrations create their `_migrations` bookkeeping table the first time the
  `migrator` service is resolved.
