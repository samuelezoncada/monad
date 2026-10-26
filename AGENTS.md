# AGENTS.md

- Monad is a single-file PHP micro-framework. `monad.php` is the entire engine; `index.php` is the app entry point. No `src/`, no build step, no Composer required.
- `monad.php` returns `$app` when required and never dispatches. `index.php` (or the `monad` CLI) calls `$app->dispatch()`.
- PHP 8.0+, PDO extension. Dev server: `php -S localhost:8000 -t .`
- `cp monad.example.ini monad.ini` on first clone — `monad.ini` is gitignored. Config is loaded into `$app->config` only (never exported to the environment).
- `$app->root` is the app directory; `monad.ini`, views, db, cache and migrations resolve from it. It defaults to the entry script's directory; `$app->setRoot()` overrides it. The `monad` CLI uses the working directory. Global handlers are installed by `dispatch()`, not on include.
- Built-in services (all opt-in, lazy): `db` (+`transaction`), `session`, `flash`, `csrf` (enforce via `verifyCsrf` middleware), `auth` (+`requireAuth` middleware), `cache`, `migrator`. Read `README.md` for the full API.
- Tests run via `./monad test` (not PHPUnit). Container resets between cases. Set `$app->config->db = ['path' => ':memory:']` in `beforeEach` for DB tests.
- `MagicObject` caches factory results as singletons. Use `$app->reset()` to force re-resolution.
- Templates use dot-notation: `html.foo.bar` → `./html/foo/bar.php`. Layout is skipped during HTMX requests.
- Autoloader: `Monad\Foo\Bar` → `./Monad/Foo/Bar.php`. Composer autoloader auto-detected if present.
