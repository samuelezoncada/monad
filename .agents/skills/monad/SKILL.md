---
name: monad
description: Single-file PHP micro-framework
license: MIT
metadata:
  audience: agents
  workflow: php
---

## What this is

Monad: a dependency-free PHP 8.0+ micro-framework for SSR and HTMX. The engine is one file, `monad.php`; `index.php` is the app entry. `README.md` is canonical for the full API.

## Invariants

- Engine logic lives only in `monad.php`. `index.php` and the `monad` CLI are launchers (`require`, `setRoot`, `dispatch`) and must stay trivial.
- `monad.php` returns `$app` and never dispatches. Global error handlers install in `dispatch()` (or `$app->handleErrors()`), not on include.
- `$app->root` is the app directory; `monad.ini`, views, db, cache and migrations resolve from it. Default: entry script's directory. CLI: working directory. Override with `setRoot()`.
- Config goes to `$app->config` only; it is never exported to `getenv()`/`$_ENV`.
- Templates: strings are wrapped in `MagicValue` and escaped; ints, floats, bools and `null` stay native; arrays become `ViewContext` (use `count()`/`isEmpty()`, not `empty()`).
- Cache stores scalars and arrays only; `set()`/`remember()` throw on values containing objects.
- Services are lazy singletons; `$app->reset()` clears them.

## Commands

```bash
php -S localhost:8000 -t .     # dev server
./monad test                   # all tests (tests.php)
./monad test tests/            # recursive (*Test.php / *test.php)
./monad test tests.php --filter=csrf
./monad help
./monad migrate                # also migrate:rollback, migrate:status
```

## DI

`$app->bind('name', fn($app) => ...)`, then `$app->name`. Bind by FQCN and use `$app->get(Foo::class)` for autocompletion. Overriding an internal service requires its `Monad\*` interface (`Monad\DB`, `Monad\Cache`, ...). `$app->share('name', fn($app) => ...)` exposes `$view->name`; `auth`, `flash`, `csrf` are shared already.

## Routing & middleware

```php
$app->addRoute('GET', '/users/:id', $handler, ['mw']);
$app->group('/admin', ['requireAuth'], fn($app) => $app->addRoute('GET', '/', $handler));
$app->use('verifyCsrf');       // global middleware
```

- Params: `$app->request->params['id']` (percent-decoded). Handlers: closures or `[Class::class, 'method']`.
- Middleware: `fn($app, $next)`; call `$next($app)`. Named middleware must be bound; an unbound name throws.
- 404 has a body; wrong verb → 405 + `Allow`. JSON vs HTML follows `Accept`.

## Views

Dot notation: `html.pages.home` → `<root>/html/pages/home.php`. Layout: `$app->response->layout = '...'`, skipped on HTMX requests. `$slot` is available in layouts.

```php
<?= $view->data->name ?>                      <!-- escaped -->
<?= number_format($view->data->price, 2) ?>   <!-- native number -->
<?= $view->data->name->raw() ?>               <!-- bypass (rare) -->
<?= $view->partial('sub', [...]) ?>           <!-- RawHtml -->
<?= $view->dump($x) ?>                        <!-- RawHtml -->
```

`$view->session`, `$view->config`, `$view->request`, `$view->auth`, `$view->flash`, `$view->csrf` are proxies: strings read through them are escaped, other scalars stay native. `MagicValue` and `ViewContext` are `JsonSerializable`.

## HTMX

`$app->request->htmx->is`; layout is skipped automatically. Any `$app->response->htmx->x = ...` becomes an `HX-X` header. Helpers: `htmxRedirect($url)`, `oob($template, $data)`, `stream(fn($emit, $app) => ...)`.

## Database

```php
$app->db->fetchOne($sql, $params);
$app->db->fetchAll($sql, $params);
$app->db->insert($table, $data);       // returns id
$app->db->update($table, $data, $where);
$app->db->delete($table, $where);
$app->db->execute($sql, $params);
$app->db->transaction(fn($db) => ...); // nested calls join
```

Table and column names must match `/^[a-zA-Z0-9_]+$/`; values are bound. SQLite by default (`[db] path`).

## Auth, session, flash, CSRF

`$app->auth`: `hash($pw)`, `attempt($id, $pw)`, `login($row)`, `logout()`, `user()` (no password), `id()`, `check()`. Config `[auth] table/identifier/password/loginPath`; no tables are auto-created. Protect routes with `requireAuth` (302, 401 JSON, or HX-Redirect).

`$app->session`: `get`, `set`, `has`, `regenerate`, `destroy`. Cookies are HttpOnly, SameSite=Lax, Secure behind HTTPS; behind a TLS proxy set `[server] trust_proxy = true`.

`$app->flash`: one-shot; `get` consumes, `peek` does not, `all` drains.

`$app->csrf`: `token()`, `rotate()`, `verify($t)`; `field()` and `htmxAttribute()` return `RawHtml`. Enforcement is opt-in via `verifyCsrf` (403 on mismatch).

## Cache & migrations

`$app->cache`: `get`, `set`, `has`, `remember`, `forget`, `flush`; TTL in seconds (`0` = forever). Config `[cache] path`.

Migrations return `['up' => fn($db), 'down' => fn($db)]`, run in a transaction, state in `_migrations`. Config `[migrations] path`; run via `./monad migrate*`.

## Testing

`./monad test` (not PHPUnit). `$app` and `$test` are injected; the container resets per case; hooks accumulate.

```php
$test->beforeEach(fn($app) => $app->config->db = ['path' => ':memory:']);
$test->it("desc", function($app, $test) {
    $test->expect($value)->toEqual($expected);
});
```

Assertions: `toEqual`, `toBeTrue`, `toBeFalse`, `toContain`, `toBeInstanceOf`, `assertThrows`. `$test->request($method, $uri, ['headers' => [], 'post' => [], 'files' => []])` returns `{statusCode, headers, body}`.

## Errors

`debug = true` → HTML error page with file, line and trace; `false` → generic 500. JSON for `Accept: application/json`; CLI gets plain text on STDERR. Deprecations are logged, not thrown.
