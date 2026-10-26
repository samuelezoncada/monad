# Monad

Monad is an experimental, ultra-lightweight PHP micro-framework whose entire engine is a single `monad.php`. It leverages metaprogramming and PHP magic methods to offer an extremely smooth development experience, focused on modern Server-Side Rendering (SSR) and natively integrated with HTMX.

Despite the tiny core, it ships a surprisingly complete feature set out of the box: DI container, router with onion middleware, auto-escaping view engine, PDO wrapper, sessions, flash messages, CSRF, authentication, filesystem cache, schema migrations and a built-in test runner.

## Philosophy
*   **Monofile Core**: the entire engine resides in `monad.php`; your app is a small `public/index.php` with everything private above it.
*   **Zero Dependencies**: works standalone; Composer's `vendor/autoload.php` is used if present.
*   **HTMX First**: layouts are skipped on HTMX requests and `HX-*` headers are managed for you.
*   **Onion Middleware**: process requests on the way in and on the way out.
*   **Batteries Included**: sessions, flash, CSRF, auth, cache and migrations are built-in and opt-in.

---

## Who is Monad for?

Monad is an experimental framework. It is perfect for:
*   **Rapid Prototyping**: Build APIs or SSR web apps in minutes without configuring complex build steps or downloading massive vendor directories.
*   **HTMX Enthusiasts**: If you love the hypermedia-driven approach, Monad gives you a backend that respects it perfectly, rather than fighting against JS-centric paradigms.
*   **Educational Purposes**: An excellent way to study PHP metaprogramming, closures, magic methods, and Dependency Injection containers under the hood.
*   **Micro-Apps & Hackathons**: When you just need a robust router, a fast query builder, and a clean template engine, all in one file.

It is **NOT** recommended for massive enterprise monoliths where strict static typing across thousands of files is necessary, as its reliance on `MagicObject` trades static analysis for extreme dynamic flexibility.

Monad is pre-1.0: minor releases may contain breaking changes, and the API is not frozen until 1.0.

---

## Quickstart

Monad requires **PHP 8.0+** (8.0 through 8.5 are tested in CI) with `pdo_sqlite`; sessions, flash messages, CSRF and authentication additionally need `ext-session`. The repository is the library: `monad.php`, the `monad` CLI, the test suite and the docs. Your app lives outside it.

```bash
composer require samuelezoncada/monad
```

Without Composer, copy `monad.php` (and optionally the `monad` CLI) into the app root and swap the `vendor/autoload.php` require below for `require dirname(__DIR__) . '/monad.php'`.

Give the app a `public/` docroot, with everything private above it:

```
blog/
├── public/
│   └── index.php     # entry point; the only reachable file
├── bootstrap.php     # routes and commands shared by HTTP and CLI
├── config.php        # optional settings
├── html/             # templates: html.home -> html/home.php
├── migrations/
├── cache/            # created on demand
├── db.sqlite         # created on demand
└── vendor/           # Composer dependencies (or monad.php if copied in)
```

`public/index.php` does three things: require, boot at the app root, dispatch.

```php
<?php
require dirname(__DIR__) . '/vendor/autoload.php';

$app = Monad\App::boot(dirname(__DIR__));            // the app root, not the docroot
require dirname(__DIR__) . '/bootstrap.php';

$app->dispatch();
```

`bootstrap.php` holds the app's routes and commands, so both `public/index.php` and the `monad` CLI see them:

```php
<?php
$app->addRoute('GET', '/', fn ($app) => $app->response->text('It works.'));
```

Then `php -S localhost:8000 -t public` and open http://localhost:8000.

`Monad\App::boot()` returns the instance the engine created and points it at the given root. The plain-require style works too and skips the class:

```php
$app = require dirname(__DIR__) . '/monad.php';   // engine copied into the project
$app->setRoot(dirname(__DIR__));
require dirname(__DIR__) . '/bootstrap.php';
```

Keeping `config.php`, templates and the database above `public/` is what makes them unreachable over HTTP. `monad.php` is the engine and is never edited.

All framework classes live in the `Monad` namespace (declared at the top of `monad.php`), so app code typically starts with:

```php
use Monad\App;
use Monad\MagicObject;
use Monad\MagicValue;
use Monad\RawHtml;
```

---

## `MagicObject` & Services

Every framework service (App, Request, Response, DB) is a `MagicObject`: properties and closures are resolved lazily and bound to the instance. Factory closures receive `$app`, and `$this` is the instance, so services can chain each other.

```php
$app->bind('myService', function($app) {
    return new MyService();
});
$app->myService->sayHello();   // resolved once, then cached

// Bind by class name for autocompletion:
$app->bind(MyService::class, fn($app) => new MyService());
$app->get(MyService::class)->sayHello();
```

Services are singletons; `$app->reset()` clears resolved instances. Override an internal service by rebinding the same name — the replacement should provide the methods documented by the matching `Monad\*` interface (e.g. `Monad\DB`). Built-ins are `MagicObject` instances, so those interfaces are structural documentation rather than runtime `instanceof` checks.

### Shared view globals
`$app->share('stats', fn($app) => new StatsService())` exposes `$view->stats` to every template, resolved lazily. `auth`, `flash` and `csrf` are already shared.

---

## Routing and Middleware

Registering routes is simple. Monad supports dynamic URL parameters, route groups, named middleware and full controllers.

```php
// A global middleware
$app->use(function ($app, $next) {
    // Do something before...
    $response = $next($app);
    // Do something after...
    return $response;
});

// Route group protected by a named middleware
$app->group('/admin', ['requireAuth'], function($app) {

    $app->addRoute('GET', '/dashboard', function($app) {
        $app->response->render('admin.dashboard', ['title' => 'Admin Dashboard']);
    });

});
```

Full controllers are supported by passing an array `[Class::class, 'method']`.

Routing behaviors:

* URL parameters use `:param` syntax: `/users/:id` → `$app->request->params['id']` (percent-decoded).
* **404 responses have a body** (JSON or HTML depending on the `Accept` header).
* A known path with the wrong verb returns **405 with an `Allow` header**.
* Middleware can be bound by name with `$app->bind()` and referenced as a string. An unbound name throws a clear error. A service closure used as middleware (one that only accepts `$app`) is rejected at dispatch time instead of silently aborting the chain.
* `OPTIONS` is answered automatically with `204` and an `Allow` header for any path that has routes. A `405` also lists `HEAD` (for `GET` routes) and `OPTIONS`.

---

## Requests and Responses

The request object exposes the current request without hiding PHP's superglobals:

```php
$app->request->method;                 // GET, POST, ...
$app->request->path;                   // /users/42
$app->request->params['id'];           // route parameters
$app->request->getQueryVar('page');    // $_GET, with an optional default
$app->request->getHeader('HX-Request');
$app->request->body();                 // raw request body
```

Body input is parsed from the request body, not just `$_POST`:

```php
$app->request->input();                    // all body input as an array
$app->request->input('email');             // one value, or null
$app->request->input('page', 1);           // with a default
$app->request->getPostVar('email');        // historic alias of input('email')
$app->request->json();                     // decoded JSON body, or null
$app->request->json('items');              // one key from the JSON body
```

This works for normal POST forms (including multipart), URL-encoded `PUT`/`PATCH`/`DELETE` bodies, and `application/json` or `application/*+json` requests. PHP only parses multipart bodies for POST, so non-POST uploads should use a URL-encoded or JSON body. `$app->request->htmx` exposes `is`, `target`, `trigger`, `triggerName`, `boosted` and `currentUrl`.

Response helpers emit the body and mark the response as sent:

```php
$app->response->text('plain text');                  // text/plain
$app->response->html('<h1>ready</h1>');              // text/html, emitted verbatim
$app->response->json(['ok' => true]);
$app->response->redirect('/login');
$app->response->render('html.home', ['title' => 'Home']);
```

`statusCode`, `headers`, `setStatusCode()` and `setHeader()` are available when a helper is not enough. A second response call after the body is sent is ignored, so a missing `return` cannot corrupt the response.

---

## Views and Templates

PHP is your templating engine, and Monad makes it safe and elegant. Data passed to the view is wrapped in a **recursive** context: strings are escaped automatically, while numbers, booleans and `null` stay native PHP values.

### Rendering
```php
$app->response->layout = 'html.main_layout';
$app->response->render('html.pages.home', [
    'user' => ['name' => 'Sam', 'balance' => 1250.50],
    'items' => [['name' => 'Pizza', 'price' => 12], ['name' => 'Soda', 'price' => 2]]
]);
```

### Inside the Template (`html/pages/home.php`)
```php
<?php /** @var Monad\View $view */ ?>

<!-- Automatic Escaping (XSS Protected) -->
<h1>Welcome, <?= $view->data->user->name ?></h1>

<!-- Numbers stay native PHP values -->
<p>Balance: <?= number_format($view->data->user->balance, 2) ?> €</p>

<!-- Recursive Loops -->
<ul>
    <?php foreach ($view->data->items as $item): ?>
        <li><?= $item->name ?>: <?= number_format($item->price, 2) ?> €</li>
    <?php endforeach; ?>
</ul>

<!-- Access to the global context ($session, $config, $request) -->
<?php if ($view->session->has('auth')): ?>
    <p>Logged in as <?= $view->session->get('username') ?></p>
<?php endif; ?>

<!-- Shared globals (auth / flash / csrf / anything you shared) -->
<?php if ($view->auth->check()): ?>
    <p>Ciao, <?= $view->auth->user()->name ?></p>
<?php endif; ?>
```

Templates are resolved using **dot notation** relative to the app root. For example, `html.admin.dashboard` maps to `./html/admin/dashboard.php`. Template names are validated (no slashes, no `..`) so they can never escape the view root.

### Escaping semantics
* Strings are wrapped in `MagicValue`, whose `__toString()` HTML-escapes automatically. Helpers on strings: `->default($fallback)`, `->json()`, `->raw()`.
* Numbers, floats, booleans and `null` stay native, so `number_format($view->data->price, 2)`, `date('Y', $view->data->ts)`, `$view->data->count > 0` and `empty($view->data->zero)` behave exactly like plain PHP.
* `MagicValue` implements `Stringable` and `JsonSerializable`: an escaped value still serializes to its raw form in `json_encode`.
* Arrays are wrapped in `ViewContext` so their elements are escaped on read. A wrapped array is an object, so `empty()` cannot describe it: use `count($view->data->items)` or `$view->data->items->isEmpty()`. `ViewContext` is `JsonSerializable`, so `json_encode($view->data->items)` emits the array.
* Service objects reached from a template (`$view->request`, `$view->session`, `$view->auth`) are wrapped in a `ViewProxy` so values read *through* them are escaped too.
* Markup that is already safe is returned as `RawHtml` and emitted verbatim: `$view->partial(...)`, `$view->dump(...)` and the CSRF helpers.

### Partials & debugging
```php
<?= $view->partial('partials.header', ['title' => 'Home']) ?>
<?= $view->dump($data) ?>   <!-- <pre> var_dump, escaped, trusted markup -->
```
Outside templates, use `$app->dump(...)`, and `$app->dumpHtml(...)` to get the HTML string.

---

## HTMX Integration

Monad knows when a request comes from HTMX (`$app->request->htmx->is`). Calling `$app->response->render(...)` during an HTMX request **automatically skips the layout**, returning only the requested fragment.

Send commands to the frontend through the response's magic `htmx` object — any property becomes an `HX-*` response header:

```php
// Trigger a JS event on the client
$app->response->htmx->trigger = 'updateList';
$app->response->render('user_list', [...]);
```

These headers are emitted by `render()` only. Setting one and then calling `json()`, `text()`, `html()` or `redirect()` drops it; use `htmxRedirect()` when the client should navigate.

### HTMX-specific response helpers
```php
// Navigate the browser instead of swapping a fragment (e.g. after login/logout)
$app->response->htmxRedirect('/login');

// Append an out-of-band fragment after the main body
// (its root element must carry hx-swap-oob="true")
$app->response->oob('cart.count', ['n' => 3]);

// Server-Sent Events stream. $emit returns false once the client disconnects.
$app->response->stream(function($emit, $app) {
    while ($emit('tick')) {
        usleep(1_000_000);
    }
});
```

---

## Logging

`$app->logger($message)` writes to PHP's `error_log` with the request method and path as a prefix. The `log` middleware logs the status code and duration for every request:

```php
$app->use('log');
```

Replace the sink by rebinding `logger`; the closure receives the app and the message:

```php
$app->bind('logger', function ($app, string $message) {
    file_put_contents($app->root . '/app.log', $message . "\n", FILE_APPEND);
});
```

`$app->wantsJson()` is the shared `Accept: application/json` check used by the built-in error, 404, 405 and auth responses.

---

## Database

Monad uses SQLite by default (configurable in `config.php`).
The DB service includes quick methods to avoid writing tedious SQL boilerplate:

```php
// Read
$user = $app->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => 1]);
$users = $app->db->fetchAll('SELECT * FROM users');

// Insert (Returns the ID)
$id = $app->db->insert('users', ['username' => 'mario', 'role' => 'admin']);

// Update (Returns affected rows)
$app->db->update('users', ['role' => 'user'], ['id' => $id]);

// Delete
$app->db->delete('users', ['id' => $id]);

// Transactions: commit on success, rollback and rethrow on any Throwable.
// Nested transaction() calls join the outer transaction.
$app->db->transaction(function($db) {
    $db->insert('users', ['username' => 'mario']);
    $db->update('accounts', ['balance' => 100], ['id' => 1]);
});
```

Table and column names are validated against `/^[a-zA-Z0-9_]+$/` — invalid names throw `InvalidArgumentException`. All values are bound parameters, so queries are injection-safe.

---

## Authentication

The `auth` service is session-based and reads a user table you define. It is fully opt-in: nothing touches the database until you call it, and no table is auto-created.

### Configuration (`config.php`)
```php
return [
    'auth' => [
        'table' => 'users',
        'id' => 'id',               // primary key column
        'identifier' => 'email',
        'password' => 'password',
        'loginPath' => '/login',
    ],
];
```

### Service API
```php
// Registration
$app->db->insert('users', [
    'email'    => 'mario@example.com',
    'password' => $app->auth->hash('secret123'),
]);

// Login
if ($app->auth->attempt('mario@example.com', 'secret123')) {
    $app->response->redirect('/dashboard');
}

// Auth state
$app->auth->check();   // bool
$app->auth->id();      // user id or null
$app->auth->user();    // row without the password column, or null

// Logout (clears the identity and regenerates the session)
$app->auth->logout();
```

### Protecting routes
`requireAuth` is a built-in middleware. Reference it by name on a route, a group, or globally:

```php
$app->use('requireAuth');
$app->addRoute('GET', '/dashboard', handler, ['requireAuth']);
$app->group('/admin', ['requireAuth'], function($app) { ... });
```

When unauthenticated it: redirects to `loginPath` (302) for browser requests, answers `401 {"error":"Unauthenticated"}` for JSON clients, and sends an `HX-Redirect` for HTMX requests.

### Notes
* The login form and `/login` route are up to your app.
* Login state is just the value of `auth.id` stored in the session (`_auth_id`); `login()` regenerates the session id to prevent fixation.
* The password hash is never exposed through `user()`.

---

## Sessions, Flash & CSRF

### Sessions
Cookies are hardened by default: `HttpOnly`, `SameSite=Lax`, `Secure` behind HTTPS, strict session id mode.

When a reverse proxy terminates TLS, set `server.trust_proxy = true` and have the proxy send `X-Forwarded-Proto: https`. Enable it only when the app cannot be reached directly, otherwise clients could spoof the header.

```php
$app->session->set('theme', 'dark');
$app->session->get('theme');     // 'dark'
$app->session->has('theme');     // true
$app->session->regenerate();     // session_regenerate_id
$app->session->destroy();
```

### Flash messages
One-shot session messages, the backbone of the POST → redirect → show pattern. Reading a key consumes it.

```php
// After handling a POST, before redirecting
$app->flash->set('success', 'Transazione salvata!');
$app->response->redirect('/transactions');

// In the next request's template or controller
$app->flash->has('success');      // true
$app->flash->get('success');      // 'Transazione salvata!' (and it's now gone)
$app->flash->peek('success');     // read without consuming
$app->flash->all();               // drain everything
```

### CSRF
Tokens are per-key, capped at 32 entries. Generation and verification are always available:

```php
$app->csrf->token();            // generate/read
$app->csrf->rotate();
$app->csrf->verify($token);
```

Markup helpers return `RawHtml` so the view layer emits them verbatim:
```html
<!-- Plain HTML form -->
<?= $view->csrf->field() ?>               <!-- <input type="hidden" name="_csrf" ...> -->
<!-- HTMX: put on <body>, it is inherited by all requests -->
<body <?= $view->csrf->htmxAttribute() ?>> <!-- hx-headers='{"X-CSRF-Token":"..."}' -->
```

Enforcement is **opt-in** via the `verifyCsrf` middleware (checks POST/PUT/PATCH/DELETE against `_csrf` in the parsed body — form-encoded or JSON — or the `X-CSRF-Token` header, returns 403 on mismatch):

```php
$app->use('verifyCsrf');          // globally
// or per route/group: ['verifyCsrf']
```

---

## Caching

A small filesystem cache with atomic writes (temp file + rename, so a concurrent reader never sees a half-written entry).

Values are serialized, but class instantiation is disabled when reading, so only scalars and arrays are cacheable. `set()` (and therefore `remember()`) throws `InvalidArgumentException` if the value contains an object; an object entry written by anything else is treated as a miss.

```php
$app->cache->set('key', $value, 3600);     // TTL in seconds; 0 = never expires
$app->cache->get('key', $default);
$app->cache->has('key');
$app->cache->remember('key', 3600, fn() => $expensiveComputation());
$app->cache->forget('key');
$app->cache->flush();                       // returns the number of removed files
```

```php
// config.php
return [
    'cache' => ['path' => 'cache'],   // relative to the app root
];
```

The default is `<app root>/cache`, which is private because the docroot is `public/`.

---

## Migrations

A migration is a plain PHP file returning `up`/`down` closures that receive the `db` service. Files run inside a transaction (SQLite supports transactional DDL), and state lives in a `_migrations` table.

`migrations/001_create_users.php`:
```php
return [
    'up' => function($db) {
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password TEXT)');
    },
    'down' => function($db) {
        $db->execute('DROP TABLE users');
    },
];
```

```php
// config.php
return [
    'migrations' => ['path' => 'migrations'],   // relative to the app root
];
```

---

## CLI

Monad is not just for HTTP! It includes a built-in micro CLI. Commands are registered on `$app` in the app's `bootstrap.php`, the same file `public/index.php` requires, and executed with the `monad` script. The CLI roots itself at the current working directory, loads `<app root>/bootstrap.php` when present, and then dispatches.

```php
// bootstrap.php
$app->addCommand('db:reset', function($app, $args) {
    echo "Resetting database...\n";
    $app->db->execute('DROP TABLE IF EXISTS users');
    echo "Done!\n";
});
```

You can execute it from the terminal:
```bash
./monad db:reset            # CLI copied into the project
vendor/bin/monad db:reset   # installed with Composer
```

The CLI reads that project's `config.php`, `bootstrap.php` and `migrations/` — the same files the web entry point uses, which is why configuration lives in a file rather than inside `public/index.php`. When Monad is installed as a dependency, the CLI is also available as `vendor/bin/monad`.

Built-in commands:

| Command | Purpose |
| --- | --- |
| `./monad help` | List available commands |
| `./monad migrate` | Apply all pending migrations (one batch) |
| `./monad migrate:rollback` | Undo the last batch |
| `./monad migrate:status` | Show which migrations have run / are pending |
| `./monad test` | Run the test suite |

---

## Embedding Monad in Other Applications

Monad is great on its own, but it's also designed to be embeddable. If you `require` or `include` `monad.php` from another script, it will **not** execute the router. Instead, it will return the `$app` instance, allowing you to use Monad as a service container or a library.

The app root defaults to the directory of the real entry script, so a project that keeps the engine in a subfolder gets its own config, templates and database:

```php
// ../blog/public/index.php
$app = require __DIR__ . '/../../monad/monad.php';
$app->setRoot(dirname(__DIR__));
$app->addRoute('GET', '/', fn ($app) => $app->response->render('html.home'));
$app->dispatch();
```

`config.php`, `html/`, `db.sqlite`, the cache directory and `migrations/` all resolve from `../blog`, not from the framework checkout. If the entry point is not the app root, call `$app->setRoot('/path/to/app')` before resolving any service.

### Installing via Composer

When the engine is autoloaded instead of required, `$app = require 'monad.php'` is not available: Composer includes the file for its classes and discards the return value, and requiring it a second time would redeclare the engine. `monad.php` is mapped with `classmap`, so boot through the class instead:

```php
// ../blog/public/index.php
require dirname(__DIR__) . '/vendor/autoload.php';

$app = Monad\App::boot(dirname(__DIR__));   // the app root, not the docroot
$app->addRoute('GET', '/', fn ($app) => $app->response->render('html.home'));
$app->dispatch();
```

`App::boot()` returns the instance the engine created and points it at the given root (identical roots are a no-op, so resolved services survive). Pick one entry style per app: either require the file directly or boot through Composer, not both.

Global error and exception handlers are installed by `dispatch()`, not when the file is included. Requiring the engine from a cron script therefore does not take over the host process's error handling; call `$app->handleErrors()` if you want the error page without routing.

The engine never auto-dispatches: the entry point decides when to run. Running `monad.php` directly does nothing.

### Use Monad in standalone scripts
You can reuse your database connection, configuration, and services in cron jobs or background tasks:

```php
// cron.php
require __DIR__ . '/vendor/autoload.php';
$app = Monad\App::boot(__DIR__);

// Reuse the DB service
$users = $app->db->fetchAll("SELECT * FROM users WHERE active = 0");
echo "Found " . count($users) . " inactive users.";
```

### Manual Dispatching
If you embed Monad in another framework or a custom entry point, you can trigger the routing manually. One example of this is Monad's own testing script (see below).

---

## Testing

Monad features a zero-boilerplate, CLI-driven testing engine. Tests run in isolation with a freshly reset container for each test case.

### Running Tests

Tests must be run exclusively via the Monad CLI:

```bash
# Run the default test suite (tests.php)
./monad test

# Run a single specific test file
./monad test tests/UserTest.php

# Run all test files (ending in *Test.php or *test.php) inside a folder recursively
./monad test tests/

# Only tests whose description contains "csrf"
./monad test tests.php --filter=csrf
```

### Writing Tests

Test files are completely free of setup boilerplate. The CLI automatically boots the framework and injects `$app` (the application instance) and `$test` (the test suite helper) directly into the test file scope.

Example (`tests.php`):

```php
// Hooks run around each case (they accumulate)
$test->beforeEach(fn($app) => $app->config->db = ['path' => ':memory:']);

$test->it("handles basic request simulation", function($app, $test) {
    $app->addRoute('GET', '/ping', fn($app) => $app->response->json(['ok' => true]));

    $res = $test->request('GET', '/ping');
    $test->expect($res->statusCode)->toEqual(200);
    $test->expect($res->body)->toContain('"ok":true');
});
```

### Test API

- `$test->it(string $desc, callable $fn)`: Registers a test case.
- `$test->beforeEach(callable $fn)` / `$test->afterEach(callable $fn)`: Lifecycle hooks, run per case.
- `$test->beforeAll(callable $fn)` / `$test->afterAll(callable $fn)`: Lifecycle hooks, run once.
- `$test->expect($actual)`: Starts a fluent assertion chain.
  - `->toEqual($expected)`: Strict equality (===).
  - `->toBeTrue()` / `->toBeFalse()`: Boolean checks.
  - `->toContain($needle)`: String or array containment.
  - `->toBeInstanceOf($class)`: Class type check.
- `$test->assertThrows(callable $fn, string $exceptionClass)`: Asserts that code throws a specific exception.
- `$test->request(string $method, string $uri, array $options = [])`: Simulates an in-process HTTP request. Options: `headers`, `post`, `files`, `body` (raw request body) and `contentType`. Returns `{statusCode, headers, body}`.

---

## Configuration (`config.php`)

`config.php` lives in the app root and returns an array; each key becomes `$app->config->key`. The file is optional — without it the app runs on defaults. It must stay pure data: the container re-executes it on every `reset()`/`setRoot()`, so no side effects and no function declarations.

```php
// config.php
return [
    'debug' => true,   // set false before deploying

    'server' => [
        'trust_proxy' => false,   // honour X-Forwarded-Proto behind a TLS proxy
    ],

    'db' => [
        'path' => 'db.sqlite',    // SQLite; relative paths resolve from the app root
    ],

    // Optional: only when templates live somewhere other than the app root.
    // 'views' => ['path' => 'tpl'],

    'auth' => [
        'table' => 'users',
        'id' => 'id',
        'identifier' => 'email',
        'password' => 'password',
        'loginPath' => '/login',
    ],
];
```

Omitted keys fall back to the engine's defaults: `debug` is `true`, `db.path` is `db.sqlite`, `cache.path` is `cache`, `migrations.path` is `migrations`, `auth.id` is `id`, and templates resolve from the app root (`html.home` → `<root>/html/home.php`). Relative paths always resolve from the app root, never the working directory. When `views.path` is set, template names are relative to it: with `views.path = 'tpl'`, `home` resolves to `<root>/tpl/home.php`.

Nothing is exported to `getenv()` or `$_ENV`, so values cannot leak into child processes. Because the docroot is `public/`, the file is not reachable over HTTP — and since it is committed rather than generated, there is no setup step.

Per-environment values are still possible without a second config file: `config.php` is ordinary PHP, so it can read an environment variable or check `PHP_SAPI` and return a different array. Keep secrets out of version control by reading them from the environment at this point if you need to; the engine itself never touches `getenv()`.

---

## Tips

### 1. Production Deployment
Since Monad routes everything through `public/index.php`, point the server's document root at `public/` and send all traffic there. Nothing above that directory is reachable.

Example for **Nginx**:
```nginx
root /var/www/blog/public;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 2. Dev-Friendly Error Page
When `debug = true` in `config.php`, Monad catches any unhandled exceptions or fatal errors and displays a beautiful, dev-friendly HTML error page showing the exact file, line number, and a full stack trace. When `debug = false` (e.g. in production), it safely hides the details and returns a generic 500 Server Error to protect your application.

Error handling details:
* API requests (`Accept: application/json`) get JSON errors instead of HTML.
* CLI errors are reported as plain text on STDERR.
* PHP deprecations are logged, not promoted to exceptions.
* A reentrancy guard prevents an error raised while reporting an error from replacing the original one.
