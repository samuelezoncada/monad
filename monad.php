<?php

/*
 * Monad — A single-file PHP micro-framework.
 *
 * Architecture overview:
 *
 *   MagicObject   The base container. Holds all state in $props and resolves
 *                 services lazily via __get(). Every service is a Singleton
 *                 by default: the factory runs once, the result is cached.
 *
 *   App           Extends MagicObject. The single $app instance is the entry
 *                 point for routing, DI, CLI dispatch, and configuration.
 *
 *   Services      Registered with $app->bind('name', fn($app) => ...).
 *                 Closures are bound to $app via ->call(), so $this inside
 *                 a factory refers to $app itself.
 *
 *   Dispatch      On HTTP: matches the request against registered routes,
 *                 builds an Onion middleware pipeline, and runs it.
 *                 On CLI: reads $argv and delegates to registered commands.
 *
 *   Testing       $app->test exposes a zero-boilerplate TestSuite.
 *                 Run with: ./monad test
 */

namespace Monad;

use PDO;
use PDOStatement;
use Throwable;
use ErrorException;

// --- SERVICE INTERFACES ---
//
// These interfaces document the public surface of each service for IDEs and
// app-side type hints. Built-ins are MagicObject instances resolved from
// closures, so they do not declare these interfaces at runtime: $app->db is not
// an `instanceof DB`. A replacement bound with $app->bind() should provide the
// same methods; it is a structural contract, not a runtime check.

interface DB {
    public function query(string $sql, array $params = []): PDOStatement;
    public function fetchOne(string $sql, array $params = []): array|null;
    public function fetchAll(string $sql, array $params = []): array;
    public function execute(string $sql, array $params = []): int;
    public function insert(string $table, array $data): int;
    public function update(string $table, array $data, array $where): int;
    public function delete(string $table, array $where): int;
    /** Commits on return, rolls back on any Throwable, then rethrows. */
    public function transaction(callable $fn): mixed;
}

/**
 * @property-read string $dir
 * @method array files()
 * @method array pending()
 * @method int lastBatch()
 * @method string|null file(string $name)
 * @method mixed runFile(string $path, string $direction)
 */
interface Migrator {}

/** One-shot session messages: get() consumes the key, peek() does not. */
interface Flash {
    public function set(string $key, mixed $value): void;
    public function get(string $key, mixed $default = null): mixed;
    public function peek(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
    public function all(): array;
}

interface Auth {
    public function attempt(string $identifier, string $password): bool;
    public function login(array $user): void;
    public function logout(): void;
    /** The authenticated row without its password column, or null. */
    public function user(): ?array;
    public function id(): mixed;
    public function check(): bool;
    public function hash(string $password): string;
}

interface Cache {
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    public function has(string $key): bool;
    public function forget(string $key): bool;
    public function flush(): int;
    public function remember(string $key, int $ttl, callable $producer): mixed;
}

interface Session {
    public function get(string $name): mixed;
    public function has(string $name): bool;
    public function set(string $name, mixed $value): void;
    public function destroy(): bool;
    public function regenerate(bool $del = true): bool;
}

interface CSRF {
    public function token(string $key = 'default'): string;
    public function rotate(string $key = 'default'): string;
    public function verify(?string $token, string $key = 'default'): bool;
    /** <input type="hidden" name="_csrf" ...> for plain HTML forms. */
    public function field(string $key = 'default'): RawHtml;
    /** hx-headers='{"X-CSRF-Token":"..."}' — put it on <body>, HTMX inherits it. */
    public function htmxAttribute(string $key = 'default'): RawHtml;
}

/**
 * @property-read string $method
 * @property-read string $path
 * @property-read array $params
 * @property-read MagicObject $htmx
 */
interface Request {
    /** Raw request body, exactly as it arrived. */
    public function body(): string;
    /**
     * Parsed body input: $_POST for form/multipart POSTs, a decoded urlencoded or
     * JSON body for PUT/PATCH/DELETE and JSON requests. With no key, returns all.
     */
    public function input(?string $key = null, mixed $default = null): mixed;
    /** Decoded JSON body (or one key); null when the body is absent or invalid. */
    public function json(?string $key = null, mixed $default = null): mixed;
    public function getQueryVar(string $name, mixed $default = null): mixed;
    /** Body input under its historic name; equivalent to input($name, $default). */
    public function getPostVar(string $name, mixed $default = null): mixed;
    public function getHeader(string $name): mixed;
}

/**
 * @property int $statusCode
 * @property array $headers
 * @property string|null $layout
 * @property bool $sent Whether a body has already been emitted for this response.
 * @property-read MagicObject $htmx
 */
interface Response {
    public function setStatusCode(int $code): void;
    public function setHeader(string $name, string $value): void;
    public function redirect(string $url, int $code = 302): void;
    public function json(mixed $data, int $code = 200): void;
    /** Plain text body (Content-Type: text/plain). */
    public function text(string $body, int $code = 200): void;
    /** Already-safe HTML body, emitted without a wrapper (Content-Type: text/html). */
    public function html(string $body, int $code = 200): void;
    /** Sends HX-Redirect with an empty 200 body, so HTMX navigates instead of swapping. */
    public function htmxRedirect(string $url): void;
    /** Queues an hx-swap-oob fragment, appended after the main body. */
    public function oob(string $template, array $data = []): void;
    /** Server-sent events. $producer receives (callable $emit, App $app). */
    public function stream(callable $producer): void;
    public function partial(string $template, array $data = []): string;
    public function render(string $template, array $data = [], int $statusCode = 200): void;
}

/**
 * @property-read ViewContext $data
 * @property-read MagicObject $config
 * @property-read MagicObject $session
 * @property-read MagicObject $request
 * @property-read MagicValue|ViewContext $balance
 * @property-read MagicValue|ViewContext $prefix
 * @property-read Auth|ViewContext $auth
 * @property-read Flash|ViewContext $flash
 * @property-read CSRF|ViewContext $csrf
 * 
 * @method RawHtml partial(string $template, array $data = [])
 * @method RawHtml dump(mixed ...$vars)
 */
interface View extends \IteratorAggregate, \ArrayAccess, \Countable {}

/**
 * @method mixed default(mixed $fallback)
 * @method string json()
 * @method mixed raw()
 */
class MagicValue implements \Stringable, \JsonSerializable {
    public function __construct(private mixed $v) {}
    /** Defence in depth: an escaped value reaching json_encode used to serialise as {}. */
    public function jsonSerialize(): mixed { return $this->v; }
    public function __toString(): string { return htmlspecialchars((string)($this->v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public function default(mixed $f): mixed { return empty($this->v) ? new MagicValue($f) : $this; }
    public function json(): string { $j = json_encode($this->v); if ($j === false) throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg()); return htmlspecialchars($j, ENT_QUOTES, 'UTF-8'); }
    public function raw() { return $this->v; }
}

/**
 * Marks a string as already-safe markup so the view layer will not escape it again.
 * Returned by view helpers that legitimately produce HTML (e.g. $view->partial()).
 */
class RawHtml implements \Stringable {
    public function __construct(private string $html) {}
    public function __toString(): string { return $this->html; }
}

/** Thrown when an unknown property or service is read from a MagicObject. */
class ServiceNotFoundException extends \RuntimeException {}

/**
 * MagicObject is the foundation of Monad's dependency injection and configuration.
 * It stores resolved values in $props, and on first access resolves closure-based
 * factories from the registry. Resolved services are cached, turning closures
 * into singletons automatically.
 */
class MagicObject {
    protected array $props;
    public function __construct(array $props) { $this->props = $props; }

    public function __get(string $name): mixed {
        if (array_key_exists($name, $this->props)) return $this->props[$name];
        if (isset($this->props['registry'][$name]) && $this->props['registry'][$name] instanceof \Closure) {
            $this->props[$name] = $this->props['registry'][$name]->call($this, $this);
            return $this->props[$name];
        }
        if (isset($this->props['registry'][$name])) return $this->props['registry'][$name];
        // Fail loudly: an unknown name is a typo, not null.
        // (`$obj->maybe ?? $default` still works: PHP consults __isset() first.)
        throw new ServiceNotFoundException(static::class . ": undefined property or service '{$name}'");
    }

    public function __isset(string $name): bool { 
        return array_key_exists($name, $this->props) || isset($this->props['registry'][$name]); 
    }

    public function __set(string $name, mixed $value): void { $this->props[$name] = $value; }
    public function __unset(string $name): void { unset($this->props[$name]); }
    public function props(): array { return $this->props; }

    public function __call(string $name, array $args): mixed {
        $callable = $this->props[$name] ?? $this->props['registry'][$name] ?? null;
        if (!$callable || !is_callable($callable)) throw new \BadMethodCallException("Undefined method $name");
        return ($callable instanceof \Closure) ? $callable->call($this, $this, ...$args) : $callable($this, ...$args);
    }
}

/**
 * ViewContext wraps dynamic view parameters to automatically apply secure,
 * context-aware HTML escaping on string output via MagicValue, while allowing
 * nested data objects/arrays to also remain wrapped.
 */
class ViewContext extends MagicObject implements View, \JsonSerializable {
    /** Unlike the container, views stay lenient: a missing key yields null instead of throwing. */
    public function __get(string $name): mixed {
        return $this->wrap($this->__isset($name) ? parent::__get($name) : null);
    }

    /**
     * Escapes helper return values too, so $view->session->get('name') is as safe as
     * $view->data->name. Only strings and arrays are wrapped: those are the only things
     * that can carry markup, and wrapping numbers here would break arithmetic and
     * strict comparisons on method results.
     */
    public function __call(string $name, array $args): mixed {
        return $this->wrapResult(parent::__call($name, $args));
    }

    protected function wrapResult(mixed $v): mixed {
        return (is_string($v) || is_array($v)) ? $this->wrap($v) : $v;
    }

    protected function wrap(mixed $v): mixed {
        if ($v instanceof MagicValue || $v instanceof RawHtml || $v instanceof ViewContext) return $v;
        if (is_array($v)) return new ViewContext($v);
        // Proxy services so values read through them are escaped too.
        if ($v instanceof MagicObject) return new ViewProxy($v);
        // Only strings can carry markup; wrapping other scalars breaks >, empty() and truthiness.
        return is_string($v) ? new MagicValue($v) : $v;
    }

    /**
     * True when the wrapped array has no entries. `empty($view->data->items)` cannot
     * work because a ViewContext is an object, and objects are never empty.
     */
    public function isEmpty(): bool { return count($this->props()) === 0; }

    /** Lets `json_encode($view->data->items)` emit the array instead of `{}`. */
    public function jsonSerialize(): mixed { return $this->props(); }
    public function getIterator(): \Traversable { foreach ($this->props as $k => $v) yield $k => $this->__get($k); }
    public function offsetExists($o): bool { return isset($this->props[$o]); }
    public function offsetGet($o): mixed { return $this->__get($o); }
    public function offsetSet($o, $v): void { $this->props[$o] = $v; }
    public function offsetUnset($o): void { unset($this->props[$o]); }
    public function count(): int { return count($this->props); }
}

/**
 * Escaping decorator for service objects reached from a template ($view->auth, $view->csrf).
 *
 * It must delegate rather than copy: a MagicObject's closures are rebound to whatever
 * object they are invoked on, so a props copy would make a service read *its own* state
 * through the escaping layer — turning an internal array into a ViewContext and breaking
 * the method's declared return type. Here the call runs on the real object and only the
 * value handed to the template is wrapped.
 */
class ViewProxy extends ViewContext {
    public function __construct(private MagicObject $target) {
        parent::__construct($target->props());
    }

    public function __get(string $name): mixed {
        return $this->wrap(isset($this->target->$name) ? $this->target->$name : null);
    }

    public function __isset(string $name): bool { return isset($this->target->$name); }
    public function __set(string $name, mixed $value): void { $this->target->$name = $value; }
    public function __unset(string $name): void { unset($this->target->$name); }

    public function __call(string $name, array $args): mixed {
        return $this->wrapResult($this->target->$name(...$args));
    }

    public function getIterator(): \Traversable {
        foreach ($this->target->props() as $key => $_) yield $key => $this->__get($key);
    }

    public function count(): int { return count($this->target->props()); }
    public function props(): array { return $this->target->props(); }
}

class AssertionException extends \Exception {}

class Assertion {
    public function __construct(private mixed $actual) {}

    public function toEqual(mixed $expected): void {
        if ($this->actual !== $expected) {
            throw new AssertionException("Expected " . var_export($expected, true) . " but got " . var_export($this->actual, true));
        }
    }

    public function toBeTrue(): void {
        if ($this->actual !== true) {
            throw new AssertionException("Expected true but got " . var_export($this->actual, true));
        }
    }

    public function toBeFalse(): void {
        if ($this->actual !== false) {
            throw new AssertionException("Expected false but got " . var_export($this->actual, true));
        }
    }

    public function toContain(mixed $needle): void {
        if (is_string($this->actual)) {
            if (!str_contains($this->actual, $needle)) {
                throw new AssertionException("Expected string to contain " . var_export($needle, true));
            }
        } elseif (is_array($this->actual)) {
            if (!in_array($needle, $this->actual)) {
                throw new AssertionException("Expected array to contain " . var_export($needle, true));
            }
        } else {
            throw new AssertionException("Cannot use toContain on type " . gettype($this->actual));
        }
    }

    public function toBeInstanceOf(string $class): void {
        if (!($this->actual instanceof $class)) {
            throw new AssertionException("Expected instance of $class but got " . (is_object($this->actual) ? get_class($this->actual) : gettype($this->actual)));
        }
    }
}

class TestSuite {
    private array $tests = [];
    /** Hooks accumulate: registering a second one used to silently discard the first. */
    private array $beforeEach = [];
    private array $afterEach = [];
    private array $beforeAll = [];
    private array $afterAll = [];

    public function __construct(private App $app) {}

    private ?string $currentFile = null;

    public function setFile(string $file): void {
        $this->currentFile = $file;
    }

    public function it(string $description, callable $fn): void {
        $this->tests[] = ['desc' => $description, 'fn' => $fn, 'file' => $this->currentFile];
    }

    public function beforeEach(callable $fn): void {
        $this->beforeEach[] = $fn;
    }

    public function afterEach(callable $fn): void {
        $this->afterEach[] = $fn;
    }

    public function beforeAll(callable $fn): void {
        $this->beforeAll[] = $fn;
    }

    public function afterAll(callable $fn): void {
        $this->afterAll[] = $fn;
    }

    public function expect(mixed $actual): Assertion {
        return new Assertion($actual);
    }

    public function assertThrows(callable $fn, string $expectedClass = \Exception::class): void {
        try {
            $fn();
            throw new AssertionException("Expected exception $expectedClass was not thrown");
        } catch (\Throwable $e) {
            if (!($e instanceof $expectedClass)) {
                throw new AssertionException("Expected exception $expectedClass but got " . get_class($e));
            }
        }
    }

    public function request(string $method, string $uri, array $options = []): object {
        $backupServer = $_SERVER;
        $backupGet = $_GET;
        $backupPost = $_POST;
        $backupFiles = $_FILES;

        $_GET = [];
        $_POST = $options['post'] ?? [];
        $_FILES = $options['files'] ?? [];
        
        $method = strtoupper($method);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        $parts = parse_url($uri);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $_GET);
        }

        foreach ($options['headers'] ?? [] as $name => $value) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $_SERVER[$serverKey] = $value;
        }
        if (isset($options['contentType'])) {
            $_SERVER['CONTENT_TYPE'] = $options['contentType'];
        } elseif (isset($_SERVER['HTTP_CONTENT_TYPE'])) {
            $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];
        }

        unset($this->app->request);
        unset($this->app->response);

        // php://input cannot be written from PHP, so the test seam replaces the
        // stream reader before the request service is first used.
        if (array_key_exists('body', $options)) {
            $rawBody = (string) $options['body'];
            $this->app->request->body = fn() => $rawBody;
        }

        // Stub header emission only; redirect()/json()/text() still run the real path.
        $headers = [];
        $this->app->response->setHeader = function($res, $n, $v) use (&$headers) {
            $headers[$n] = $v;
        };

        ob_start();
        $this->app->dispatch();
        $body = ob_get_clean();

        $statusCode = $this->app->response->statusCode;

        $_SERVER = $backupServer;
        $_GET = $backupGet;
        $_POST = $backupPost;
        $_FILES = $backupFiles;

        unset($this->app->request);
        unset($this->app->response);

        return new class($statusCode, $headers, $body) {
            public function __construct(
                public int $statusCode,
                public array $headers,
                public string $body
            ) {}
            
            public function getHeader(string $name): ?string {
                foreach ($this->headers as $k => $v) {
                    if (strcasecmp($k, $name) === 0) return $v;
                }
                return null;
            }
        };
    }

    public function run(?string $filter = null): bool {
        $passed = 0;
        $failed = 0;
        $output = "";
        $currentFile = null;
        $printLine = fn() => str_repeat("-", 128) . "\n";

        $selected = $this->tests;
        if ($filter !== null && $filter !== '') {
            $selected = array_values(array_filter(
                $this->tests,
                fn($t) => stripos($t['desc'], $filter) !== false
            ));
        }
        $skipped = count($this->tests) - count($selected);

        $output .= $printLine();
        $output .= "Monad Test Suite" . ($filter ? " \033[2m(filter: $filter)\033[0m" : "") . "\n";
        $output .= $printLine();

        foreach ($this->beforeAll as $hook) $hook($this->app);

        foreach ($selected as $test) {
            if ($test['file'] !== null && $test['file'] !== $currentFile) {
                $currentFile = $test['file'];
                $output .= "\n\033[2m" . basename($currentFile) . "\033[0m\n";
            }

            $this->app->reset();

            foreach ($this->beforeEach as $hook) $hook($this->app);

            try {
                ($test['fn'])($this->app, $this);
                $output .= "\033[32m✔\033[0m {$test['desc']}\n";
                $passed++;
            } catch (AssertionException $e) {
                $output .= "\033[31m✖ {$test['desc']}\033[0m\n";
                $output .= "  ↳ \033[33m" . $e->getMessage() . "\033[0m\n";
                if (!empty($e->getFile())) {
                    $output .= "    " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
                }
                $failed++;
            } catch (\Throwable $e) {
                $output .= "\033[31m✖ {$test['desc']}\033[0m\n";
                $output .= "  ↳ \033[31;1m[Unexpected Error]\033[0m \033[33m" . $e->getMessage() . "\033[0m\n";
                $trace = $e->getTrace();
                $output .= "    Stack trace:\n";
                foreach (array_slice($trace, 0, 3) as $i => $frame) {
                    $file = isset($frame['file']) ? basename($frame['file']) : 'internal';
                    $line = $frame['line'] ?? '?';
                    $func = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? 'unknown');
                    $output .= "      #$i $file:$line -> $func()\n";
                }
                $failed++;
            }

            foreach ($this->afterEach as $hook) $hook($this->app);
        }

        foreach ($this->afterAll as $hook) $hook($this->app);

        $output .= "\n";
        $output .= $printLine();
        $output .= "   Total: " . ($passed + $failed) . " | \033[32mPassed: $passed\033[0m | "
                 . ($failed > 0 ? "\033[31mFailed: $failed\033[0m" : "Failed: 0")
                 . ($skipped > 0 ? " | \033[2mSkipped: $skipped\033[0m" : "") . "\n";
        $output .= $printLine();

        echo $output;

        return $failed === 0;
    }
}

/**
 * The Monad App - Inherits magic powers from MagicObject.
 * 
 * @property-read DB $db
 * @property-read Session $session
 * @property-read CSRF $csrf
 * @property-read Request $request
 * @property-read Response $response
 * @property-read TestSuite $test
 * @property-read object $config
 * @property-read Auth $auth
 * @property-read Flash $flash
 * @property-read Cache $cache
 * @property-read Migrator $migrator
 *
 * @method void share(string $name, callable $factory)
 * @method void dump(mixed ...$vars)
 * @method string dumpHtml(mixed ...$vars)
 * @method void logger(string $msg)
 * @method bool wantsJson()
 * @method void handleErrors()
 * @method void dispatch()
 * @method void renderError(Throwable $e)
 * @method void bind(string $name, mixed $value)
 * @method void use(mixed $middleware)
 * @method void group(string $prefix, array $middleware, callable $callback)
 * @method void addRoute(string $method, string $path, mixed $handler, array $middleware = [])
 * @method void addCommand(string $name, callable $handler)
 */
class App extends MagicObject {
    private static ?App $instance = null;

    /** @internal Registers the instance created by the engine's boot sequence. */
    public static function attach(self $app): void {
        self::$instance = $app;
    }

    /**
     * The app instance the engine created when it was included.
     *
     * `$app = require 'monad.php'` is the whole story when the file is required
     * directly. It is not when Composer's autoloader includes the file: the return
     * value is discarded, and a second `require` would redeclare the engine's
     * classes. Composer users therefore boot through this method instead:
     *
     *     require 'vendor/autoload.php';
     *     $app = Monad\App::boot(dirname(__DIR__));
     *
     * Passing a root re-points the app before anything is resolved; passing the
     * current root is a no-op rather than a reset.
     */
    public static function boot(?string $root = null): self {
        $app = self::$instance
            ?? throw new \LogicException('Monad: no instance yet. Require monad.php (or let the autoloader load Monad\App) before calling boot().');
        if ($root !== null && rtrim($root, '/') !== $app->root) $app->setRoot($root);
        return $app;
    }

    /**
     * Resolves a bound service from the container.
     * 
     * @template T of object
     * @param class-string<T> $name
     * @return T
     */
    public function get(string $name): mixed {
        return $this->__get($name);
    }

    /**
     * Resets all resolved service instances in the container,
     * forcing them to be re-resolved on next access.
     */
    public function reset(): void {
        foreach (array_keys($this->props['registry'] ?? []) as $key) {
            unset($this->props[$key]);
        }
    }

    /**
     * Points the app at a different project directory. config.php, views, the
     * database, cache and migrations all resolve from here. Call it before
     * booting; already-resolved services are discarded.
     */
    public function setRoot(string $dir): void {
        $this->props['root'] = rtrim($dir, '/');
        // An entry point outside the app root requires the engine before the app's
        // Composer dependencies are reachable; load them now that the root is known.
        // require_once makes this a no-op when the entry point already did it.
        $autoload = $this->props['root'] . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        $this->reset();
    }

    /**
     * True when the request reached the app over HTTPS.
     *
     * X-Forwarded-Proto is only honoured when server.trust_proxy is enabled, so a
     * client talking to the app directly cannot spoof it. Enable trust_proxy only
     * when a reverse proxy terminates TLS and the app is not directly reachable.
     */
    public function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $server = $this->config->server ?? [];
        $raw = is_array($server) ? ($server['trust_proxy'] ?? false) : false;
        // A quoted "false" is a string and `(bool) "false"` is true, which would enable proxy trust.
        $trustProxy = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        if (!$trustProxy) {
            return false;
        }

        // Trust the last hop; a client-supplied first value is untrustworthy when proxies append.
        $parts = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $proto = strtolower(trim((string) end($parts)));

        return $proto === 'https';
    }
}

/**
 * App root: the directory that owns config.php, views, the database, cache and
 * migrations. It defaults to the directory of the real entry script, so a
 * required engine boots with that script's directory as the app. An entry point
 * under public/ must therefore call setRoot() (or App::boot()) with the parent,
 * so paths never resolve inside the docroot. The `monad` CLI overrides it with
 * the working directory.
 */
$entryScript = get_included_files()[0] ?? __FILE__;
$root = dirname(realpath($entryScript) ?: __FILE__);

/**
 * Composer Integration & Minimalist Autoloader.
 */
foreach ([$root . '/vendor/autoload.php', __DIR__ . '/vendor/autoload.php'] as $composerAutoload) {
    if (file_exists($composerAutoload)) require_once $composerAutoload;
}

// --- APP INITIALIZATION ---
$app = new App([
    'root' => $root,
    'routes' => [],
    'globalMiddleware' => [],
    'registry' => [],
    'commands' => [
        'help' => function($app) {
            echo <<<EOT
          ┓
┏┳┓┏┓┏┓┏┓┏┫
┛┗┗┗┛┛┗┗┻┗┻ CLI
Available commands:\n
EOT;
            foreach (array_keys($app->props()['commands']) as $cmd) {
                echo "  - $cmd\n";
            }
        },
        'migrate' => function ($app, $args = []) {
            $m = $app->migrator;
            $pending = $m->pending();
            if (!$pending) { echo "Nothing to migrate.\n"; return; }
            $batch = $m->lastBatch() + 1;
            foreach ($pending as $name => $file) {
                $m->runFile($file, 'up');
                $app->db->insert('_migrations', ['name' => $name, 'batch' => $batch, 'ran_at' => date(DATE_ATOM)]);
                echo "  \033[32m↑\033[0m $name\n";
            }
            echo "Migrated " . count($pending) . " file(s), batch $batch.\n";
        },
        'migrate:rollback' => function ($app, $args = []) {
            $m = $app->migrator;
            $batch = $m->lastBatch();
            if ($batch === 0) { echo "Nothing to roll back.\n"; return; }
            $rows = $app->db->fetchAll('SELECT name FROM _migrations WHERE batch = :b ORDER BY name DESC', ['b' => $batch]);
            foreach ($rows as $row) {
                $file = $m->file($row['name']);
                if ($file === null) { echo "  \033[33m?\033[0m {$row['name']} (file missing, skipped)\n"; continue; }
                $m->runFile($file, 'down');
                $app->db->delete('_migrations', ['name' => $row['name']]);
                echo "  \033[31m↓\033[0m {$row['name']}\n";
            }
            echo "Rolled back batch $batch.\n";
        },
        'migrate:status' => function ($app, $args = []) {
            $m = $app->migrator;
            $ran = array_column($app->db->fetchAll('SELECT name, batch FROM _migrations'), 'batch', 'name');
            $all = $m->files();
            if (!$all) { echo "  (No migrations found in {$m->dir})\n"; return; }
            foreach ($all as $name => $_) {
                $state = isset($ran[$name]) ? "\033[32mran\033[0m (batch {$ran[$name]})" : "\033[33mpending\033[0m";
                echo "  " . str_pad($name, 40) . " $state\n";
            }
        },
        'test' => function($app, $args = []) {
            // Separate flags from the positional target: ./monad test tests/ --filter=csrf
            $filter = null;
            $positional = [];
            foreach ($args as $arg) {
                if (str_starts_with((string) $arg, '--filter=')) $filter = substr((string) $arg, 9);
                elseif ($arg === '--filter') $filter = '';   // value follows
                elseif ($filter === '') $filter = (string) $arg;
                else $positional[] = $arg;
            }
            $target = $positional[0] ?? 'tests.php';
            $test = $app->test;
            if (is_dir($target)) {
                $dir = new \RecursiveDirectoryIterator($target);
                $iterator = new \RecursiveIteratorIterator($dir);
                foreach ($iterator as $file) {
                    if (preg_match('/[Tt]est\.php$/', $file->getFilename())) {
                        $test->setFile($file->getPathname());
                        require_once $file->getPathname();
                    }
                }
            } elseif (file_exists($target)) {
                $test->setFile(realpath($target));
                require $target;
            } else {
                echo "Test target '$target' not found.\n";
                exit(1);
            }
            $passed = $test->run($filter);
            exit($passed ? 0 : 1);
        }
    ],
    'groupStack' => [],
    'shared' => [],
    /**
     * Registers a lazily-resolved global available in every template as $view->name.
     * Without this, exposing anything to all views meant editing the framework itself.
     */
    'share' => function ($app, string $name, callable $factory) {
        $this->props['shared'][$name] = $factory;
    },
    'dumpHtml' => function ($app, ...$vars): string {
        $out = '';
        foreach ($vars as $v) {
            ob_start(); var_dump($v); $text = (string) ob_get_clean();
            $out .= "<pre style=\"background:#1a1a1c;color:#e0e0e0;font-family:ui-monospace,monospace;font-size:.85rem;"
                  . "padding:1rem;margin:.5rem 0;border-left:4px solid #4488ff;border-radius:6px;overflow-x:auto;white-space:pre-wrap\">"
                  . htmlspecialchars($text) . "</pre>";
        }
        return $out;
    },
    'dump' => function ($app, ...$vars): void {
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            foreach ($vars as $v) { var_dump($v); }
            return;
        }
        echo $app->dumpHtml(...$vars);
    },
    // Convention: the first argument is the owner, like every other MagicObject closure.
    // Reads $_SERVER directly so it works while the request service is failing.
    'logger' => function ($app, string $msg): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $path = $_SERVER['REQUEST_URI'] ?? ($_SERVER['argv'][1] ?? '-');
        error_log("[MONAD] {$method} {$path} | {$msg}");
    },
    'wantsJson' => function ($app): bool {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    },
    'bind' => function ($app, string $name, mixed $value) { 
        unset($this->props[$name]); 
        $this->props['registry'][$name] = $value; 
    },
    'use' => function ($app, mixed $m) { $this->props['globalMiddleware'][] = $m; },
    'group' => function ($app, string $prefix, array $mw, callable $callback) {
        $this->props['groupStack'][] = ['prefix' => $prefix, 'middleware' => $mw];
        $callback($this);
        array_pop($this->props['groupStack']);
    },
    'addRoute' => function ($app, string $m, string $p, mixed $h, array $mw = []) {
        $prefix = '';
        $groupMw = [];
        foreach ($this->props['groupStack'] as $g) {
            $prefix .= $g['prefix'];
            $groupMw = array_merge($groupMw, $g['middleware']);
        }
        $path = $prefix . $p;
        $allMw = array_merge($groupMw, $mw);
        $pattern = route_pattern($path);
        $this->props['routes'][strtoupper($m)][] = ['path' => $path, 'pattern' => $pattern, 'handler' => $h, 'middleware' => $allMw];
    },
    'addCommand' => function ($app, string $name, callable $handler) {
        $this->props['commands'][$name] = $handler;
    },
    'dispatch' => function ($app) {
        $app->handleErrors();
        if (php_sapi_name() === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            $argv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
            $cmdName = $argv[1] ?? 'help';
            $args = array_slice($argv, 2);
            if (isset($this->commands[$cmdName])) {
                try {
                    return $this->commands[$cmdName]($app, $args);
                } catch (Throwable $e) {
                    $app->logger("CLI ERROR: " . $e->getMessage());
                    echo "Error: " . $e->getMessage() . "\n";
                    exit(1);
                }
            }
            echo "Command '$cmdName' not found.\n";
            return;
        }

        $method = $this->request->method;
        $path = $this->request->path;

        // HEAD is GET without a body: try any explicit HEAD routes, then the GET
        // table, and discard whatever gets rendered. Status and headers still go out.
        $head = $method === 'HEAD';
        $tables = $head ? ['HEAD', 'GET'] : [$method];
        $obLevel = ob_get_level();
        if ($head) ob_start();

        try {
            $matched = null;
            foreach ($tables as $table) {
                foreach ($this->routes[$table] ?? [] as $route) {
                    if (preg_match($route['pattern'], $path, $matches)) {
                        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                        $this->request->params = array_map('rawurldecode', $params);
                        $matched = $route;
                        break 2;
                    }
                }
            }
            if (!$matched) {
                // Distinguish "no such path" from "wrong verb for this path".
                // OPTIONS is answered automatically for any path that has routes.
                $page = function (string $title): string {
                    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>$title</title></head>"
                         . "<body style=\"font-family:system-ui,sans-serif;text-align:center;padding:4rem\">"
                         . "<h1>$title</h1></body></html>";
                };
                $allowed = [];
                foreach ($this->routes as $otherMethod => $routes) {
                    if (in_array($otherMethod, $tables, true)) continue;
                    foreach ($routes as $route) {
                        if (preg_match($route['pattern'], $path)) {
                            $allowed[$otherMethod] = true;
                            // A GET route is also a HEAD route.
                            if ($otherMethod === 'GET') $allowed['HEAD'] = true;
                            break;
                        }
                    }
                }
                if ($allowed) {
                    $allowed['OPTIONS'] = true;
                    $order = ['GET' => 0, 'HEAD' => 1, 'POST' => 2, 'PUT' => 3, 'PATCH' => 4, 'DELETE' => 5, 'OPTIONS' => 6];
                    uksort($allowed, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
                    $this->response->setHeader('Allow', implode(', ', array_keys($allowed)));

                    if ($method === 'OPTIONS') {
                        $this->response->setStatusCode(204);
                        $this->response->sent = true;
                        return;
                    }

                    if ($this->wantsJson()) $this->response->json(['error' => 'Method Not Allowed'], 405);
                    else $this->response->html($page('405 Method Not Allowed'), 405);
                    return;
                }
                if ($this->wantsJson()) $this->response->json(['error' => 'Not Found'], 404);
                else $this->response->html($page('404 Not Found'), 404);
                return;
            }

            $resolver = function ($mw) {
                if (!is_string($mw)) return $mw;
                // A bound name is required: otherwise PHP would look for a global function.
                if (!isset($this->registry[$mw])) {
                    throw new \RuntimeException("Middleware '$mw' is not bound. Register it with \$app->bind('$mw', fn(\$app, \$next) => ...).");
                }
                $resolved = $this->registry[$mw];
                // A service factory used as middleware would swallow $next; reject it early.
                if ($resolved instanceof \Closure) {
                    $ref = new \ReflectionFunction($resolved);
                    if ($ref->getNumberOfParameters() < 2 && !$ref->isVariadic()) {
                        throw new \RuntimeException("'$mw' is bound as a service, not a middleware: a middleware closure must accept (\$app, \$next).");
                    }
                }
                return $resolved;
            };

            try {
                $pipeline = array_map($resolver, array_merge($this->globalMiddleware, $matched['middleware']));
                $handler = $matched['handler'];

                // Onion pipeline: wrap the handler, then apply middleware in reverse order.
                $chain = function ($app) use ($handler) {
                    if (is_array($handler)) {
                        $controller = new $handler[0]();
                        $method = $handler[1];
                        return $controller->$method($app);
                    }
                    return $handler($app);
                };

                foreach (array_reverse($pipeline) as $mw) {
                    $next = $chain;
                    $chain = fn ($app) => $mw($app, $next);
                }
                $chain($this);
            } catch (Throwable $e) {
                $this->renderError($e);
            }
        } finally {
            // Covers handlers that echo directly, not just response->render()/json()/text().
            if ($head) { while (ob_get_level() > $obLevel) @ob_end_clean(); }
        }
    },
    'renderError' => function ($app, Throwable $e) {
        // Reentrancy guard: an error raised while reporting an error must not replace the original.
        if (!empty($this->props['_renderingError'])) {
            error_log('[MONAD] error while reporting an error: ' . $e->getMessage());
            return;
        }
        $this->props['_renderingError'] = true;

        try {
            $app->logger('ERROR: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } catch (Throwable) { /* logging must never mask the real failure */ }

        try {
            $debug = (bool) ($this->config->debug ?? false);
        } catch (Throwable) {
            $debug = false; // config itself may be what exploded
        }

        try {
            // CLI gets plain STDERR. Matching dispatch() keeps simulated HTTP requests
            // (REQUEST_METHOD set) on the real response path.
            if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
                fwrite(STDERR, sprintf(
                    "\n\033[31m%s\033[0m: %s\n  at %s:%d\n",
                    get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
                ));
                if ($debug) fwrite(STDERR, $e->getTraceAsString() . "\n");
                $this->props['_errorRendered'] = true;
                return;
            }

            $res = $this->response;
            if ($res->statusCode === 200) $res->setStatusCode(500);

            if ($this->wantsJson()) {
                $payload = $debug
                    ? ['error' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                    : ['error' => 'Internal Server Error'];
                $res->sent = false; // an error response always supersedes a partial one
                $res->json($payload, $res->statusCode);
                $this->props['_errorRendered'] = true;
                return;
            }

            if (!$debug) {
                // A production error must still render a page, not a blank 500.
                echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>500 Server Error</title></head>"
                   . "<body style=\"font-family:system-ui,sans-serif;text-align:center;padding:4rem\">"
                   . "<h1>500</h1><p>Something went wrong on our end.</p></body></html>";
                $res->sent = true;
                $this->props['_errorRendered'] = true;
                return;
            }
            // --- debug error page (falls through, still inside the guard) ---
            $msg = htmlspecialchars($e->getMessage());
            $file = htmlspecialchars($e->getFile());
            $line = $e->getLine();
            $class = htmlspecialchars(get_class($e));
        
            $snippet = "";
            if (is_readable($e->getFile())) {
                $lines = file($e->getFile()) ?: [];
                $start = max(0, $line - 6);
                $end = min(count($lines), $line + 5);
                for ($i = $start; $i < $end; $i++) {
                    $num = $i + 1;
                    $content = htmlspecialchars($lines[$i]);
                    $st = ($num === $line) ? "background:#441111;color:#ff5555;font-weight:bold;" : "";
                    $snippet .= "<div style='display:flex;$st'><span style='width:3em;opacity:0.5;user-select:none;'>$num</span><code>$content</code></div>";
                }
            }

            $css = "#monad-error{background:#0f0f10;color:#e0e0e0;font-family:system-ui,sans-serif;padding:2rem;position:fixed;top:0;left:0;width:100%;height:100%;overflow:auto;z-index:999999}
                #monad-error .cnt{max-width:1000px;margin:0 auto}
                #monad-error .hdr{border-left:4px solid #ff4444;padding-left:1.5rem;margin-bottom:2rem}
                #monad-error .type{color:#ff4444;font-size:.9rem;font-weight:bold;text-transform:uppercase}
                #monad-error .msg{font-size:1.8rem;font-weight:600;margin:.5rem 0;color:#fff}
                #monad-error .file{color:#888;font-family:monospace}
                #monad-error .snip{background:#1a1a1c;padding:1rem;overflow-x:auto;margin:2rem 0;border:1px solid #333;border-radius:8px}
                #monad-error .trc{font-family:monospace;font-size:.85rem;color:#aaa;background:#151517;padding:1rem;border-radius:8px}
                #monad-error .item{margin-bottom:.5rem;border-bottom:1px solid #222;padding-bottom:.5rem}";

            $rawTitle = get_class($e) . ': ' . $e->getMessage();
            if (strlen($rawTitle) > 120) $rawTitle = substr($rawTitle, 0, 117) . '...';
            $title = htmlspecialchars(str_replace(["\r", "\n"], ' ', $rawTitle));
            echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>$title</title><style>$css</style></head><body>
            <div id='monad-error'><div class='cnt'>
                <div class='hdr'><div class='type'>$class</div><div class='msg'>$msg</div><div class='file'>$file : $line</div></div>
                <div class='snip'>$snippet</div>
                <h3>Stack Trace</h3><div class='trc'>";
            foreach ($e->getTrace() as $i => $t) {
                $f = htmlspecialchars($t['file'] ?? 'internal'); $l = $t['line'] ?? '?'; $fn = htmlspecialchars(($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? 'unknown'));
                echo "<div class='item'>#$i <strong>$f($l)</strong>: $fn()</div>";
            }
            echo "</div></div></div></body></html>";
            $res->sent = true;
            $this->props['_errorRendered'] = true;
        } finally {
            $this->props['_renderingError'] = false;
        }
    }
]);

// Engine classes live next to this file; every other namespace is app code under $app->root.
spl_autoload_register(function ($class) use ($app) {
    $path = str_replace('\\', '/', $class);
    $base = str_starts_with($class, 'Monad\\') ? __DIR__ : $app->root;
    $file = $base . "/{$path}.php";
    if (file_exists($file)) require $file;
});

// --- GLOBAL ERROR HANDLERS ---
// Installed by dispatch(), not on include: requiring the engine must not hijack the
// host process's error handling.
$app->bind('handleErrors', function ($app) {
    if (!empty($app->props()['_handlersInstalled'])) return;
    $app->_handlersInstalled = true;

    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        // Deprecations are logged, not thrown: promoting them made every PHP upgrade a 500.
        if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
            error_log("[MONAD] Deprecated: $message in $file:$line");
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(function (Throwable $e) use ($app) {
        $app->renderError($e);
    });

    // Catch fatal runtime errors (like E_ERROR or E_PARSE) that bypass standard try/catch blocks.
    register_shutdown_function(function () use ($app) {
        if (!empty($app->props()['_errorRendered'])) return; // already reported by the exception handler
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $app->renderError(new \ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            ));
        }
    });
});

/**
 * Build a route regex from a path while quoting literal characters. Only
 * ":name" segments become capture groups.
 *
 * @internal
 */
function route_pattern(string $path): string
{
    $pattern = '';
    $offset = 0;

    if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $index => $match) {
            [$placeholder, $position] = $match;
            $name = $matches[1][$index][0];

            $pattern .= preg_quote(substr($path, $offset, $position - $offset), '#');
            $pattern .= '(?P<' . $name . '>[^/]+)';
            $offset = $position + strlen($placeholder);
        }
    }

    $pattern .= preg_quote(substr($path, $offset), '#');

    return '#^' . $pattern . '$#';
}

// --- CORE SERVICES ---
/**
 * Configuration comes from <root>/config.php, which must return an array; its
 * keys become $app->config->key. The file is optional: without it the app runs
 * on defaults. It is re-executed whenever the container resets (setRoot/reset),
 * so it must stay pure data — no side effects, no function declarations.
 */
$app->bind('config', function($app) {
    $file = $app->root . '/config.php';
    if (!is_file($file)) return new MagicObject(['debug' => true]);

    $data = require $file;
    if (!is_array($data)) {
        throw new \RuntimeException("$file must return an array, got " . gettype($data));
    }
    return new MagicObject($data);
});

$app->bind('db', function($app) {
    $path = (string) ($app->config->db['path'] ?? 'db.sqlite');
    if ($path !== ':memory:' && !str_starts_with($path, '/')) $path = $app->root . '/' . $path;
    $dsn = ($path === ':memory:') ? 'sqlite::memory:' : 'sqlite:' . $path;
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return new MagicObject([
        'pdo' => $pdo,
        'transaction' => function ($db, callable $fn) {
            if ($db->pdo->inTransaction()) return $fn($db);   // already inside one: join it
            $db->pdo->beginTransaction();
            try {
                $result = $fn($db);
                $db->pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($db->pdo->inTransaction()) $db->pdo->rollBack();
                throw $e;
            }
        },
        'query' => function ($db, string $sql, array $params = []): PDOStatement { $stmt = $db->pdo->prepare($sql); $stmt->execute($params); return $stmt; },
        'fetchOne' => function ($db, string $sql, array $params = []): array|null { $row = $db->query($sql, $params)->fetch(); return $row === false ? null : $row; },
        'fetchAll' => function ($db, string $sql, array $params = []): array { return $db->query($sql, $params)->fetchAll(); },
        'execute' => function ($db, string $sql, array $params = []): int { return $db->query($sql, $params)->rowCount(); },
        'insert' => function ($db, string $table, array $data): int {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new \InvalidArgumentException("Invalid table name");
            $safeKeys = array_filter(array_keys($data), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            if (count($safeKeys) !== count($data)) throw new \InvalidArgumentException("Invalid column name in insert data");
            $cols = implode(', ', $safeKeys);
            $pl = implode(', ', array_map(fn($k) => ":$k", $safeKeys));
            $db->execute("INSERT INTO $table ($cols) VALUES ($pl)", $data);
            return (int) $db->pdo->lastInsertId();
        },
        'update' => function ($db, string $table, array $data, array $where): int {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new \InvalidArgumentException("Invalid table name");
            $safeDataKeys = array_filter(array_keys($data), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            $safeWhereKeys = array_filter(array_keys($where), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            if (count($safeDataKeys) !== count($data) || count($safeWhereKeys) !== count($where)) throw new \InvalidArgumentException("Invalid column name in update data");
            $set = implode(', ', array_map(fn($k) => "$k = :set_$k", $safeDataKeys));
            $cond = implode(' AND ', array_map(fn($k) => "$k = :wh_$k", $safeWhereKeys));
            $params = [];
            foreach ($data as $k => $v) $params["set_$k"] = $v;
            foreach ($where as $k => $v) $params["wh_$k"] = $v;
            return $db->execute("UPDATE $table SET $set WHERE $cond", $params);
        },
        'delete' => function ($db, string $table, array $where): int {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new \InvalidArgumentException("Invalid table name");
            $safeWhereKeys = array_filter(array_keys($where), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            if (count($safeWhereKeys) !== count($where)) throw new \InvalidArgumentException("Invalid column name in delete data");
            $cond = implode(' AND ', array_map(fn($k) => "$k = :$k", $safeWhereKeys));
            return $db->execute("DELETE FROM $table WHERE $cond", $where);
        }
    ]);
});

/**
 * Schema migrations. A migration file returns ['up' => fn($db), 'down' => fn($db)].
 * State lives in a _migrations table; each file runs inside a transaction.
 */
$app->bind('migrator', function ($app) {
    $dir = (string) ($app->config->migrations['path'] ?? 'migrations');
    if (!str_starts_with($dir, '/')) $dir = $app->root . '/' . $dir;
    $dir = rtrim($dir, '/');

    $app->db->execute('CREATE TABLE IF NOT EXISTS _migrations (name TEXT PRIMARY KEY, batch INTEGER NOT NULL, ran_at TEXT NOT NULL)');

    return new MagicObject([
        'app' => $app,
        'dir' => $dir,
        'files' => function ($m): array {
            if (!is_dir($m->dir)) return [];
            $found = [];
            foreach ((glob($m->dir . '/*.php') ?: []) as $path) {
                $found[basename($path, '.php')] = $path;
            }
            ksort($found, SORT_NATURAL);   // 001_, 002_, ... 010_
            return $found;
        },
        'file' => function ($m, string $name): ?string {
            return $m->files()[$name] ?? null;
        },
        'lastBatch' => function ($m): int {
            $row = $m->app->db->fetchOne('SELECT MAX(batch) AS b FROM _migrations');
            return (int) ($row['b'] ?? 0);
        },
        'pending' => function ($m): array {
            $ran = array_column($m->app->db->fetchAll('SELECT name FROM _migrations'), 'name');
            return array_diff_key($m->files(), array_flip($ran));
        },
        'runFile' => function ($m, string $path, string $direction) {
            $migration = require $path;
            if (!is_array($migration) || !isset($migration[$direction])) {
                throw new \RuntimeException(basename($path) . " must return an array with an '$direction' closure");
            }
            if (!is_callable($migration[$direction])) {
                throw new \RuntimeException(basename($path) . ": '$direction' is not callable");
            }
            // Wrapped in a transaction so a half-applied migration cannot be left behind.
            // Note that SQLite (unlike MySQL) does support transactional DDL.
            return $m->app->db->transaction(fn($db) => $migration[$direction]($db));
        },
    ]);
});

$app->bind('session', function($app) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => $app->isHttps(),
            'use_strict_mode' => true
        ]);
    }
    return new MagicObject([
        'get' => fn ($_, string $name) => $_SESSION[$name] ?? null,
        'has' => fn ($_, string $name) => array_key_exists($name, $_SESSION),
        'set' => function ($_, string $name, mixed $value) { $_SESSION[$name] = $value; },
        'destroy' => function (): bool { $_SESSION = []; return session_destroy(); },
        'regenerate' => fn ($_, bool $del = true) => session_regenerate_id($del),
    ]);
});

/**
 * One-shot session messages: the backbone of the POST -> redirect -> show pattern.
 * Reading a key consumes it.
 */
$app->bind('flash', function ($app) {
    $app->session; // force session initialisation
    return new MagicObject([
        'set' => function ($_, string $key, mixed $value): void { $_SESSION['_flash'][$key] = $value; },
        'has' => fn ($_, string $key): bool => array_key_exists($key, $_SESSION['_flash'] ?? []),
        'peek' => fn ($_, string $key, mixed $default = null): mixed => $_SESSION['_flash'][$key] ?? $default,
        'get' => function ($_, string $key, mixed $default = null): mixed {
            $value = $_SESSION['_flash'][$key] ?? $default;
            unset($_SESSION['_flash'][$key]);
            return $value;
        },
        'all' => function (): array {
            $all = $_SESSION['_flash'] ?? [];
            $_SESSION['_flash'] = [];
            return $all;
        },
    ]);
});

/**
 * Minimal authentication over the session and PHP's own password hashing.
 * Configure with auth.table / auth.id / auth.identifier / auth.password.
 */
$app->bind('auth', function ($app) {
    $cfg = $app->config->auth ?? [];
    foreach (['id' => 'id', 'table' => 'users', 'identifier' => 'email', 'password' => 'password'] as $key => $default) {
        $value = (string) ($cfg[$key] ?? $default);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $value)) throw new \InvalidArgumentException("Invalid auth $key: '$value'");
        $cfg[$key] = $value;
    }

    return new MagicObject([
        'app' => $app,
        'table' => $cfg['table'],
        'identifier' => $cfg['identifier'],
        'passwordColumn' => $cfg['password'],
        'idColumn' => $cfg['id'],
        'hash' => fn ($_, string $password): string => password_hash($password, PASSWORD_DEFAULT),
        'attempt' => function ($auth, string $identifier, string $password): bool {
            $row = $auth->app->db->fetchOne(
                "SELECT * FROM {$auth->table} WHERE {$auth->identifier} = :id",
                ['id' => $identifier]
            );
            $hash = $row[$auth->passwordColumn] ?? '';
            // password_verify against '' still costs time, which keeps unknown and
            // known identifiers roughly indistinguishable.
            if (!password_verify($password, is_string($hash) ? $hash : '')) return false;
            $auth->login($row);
            return true;
        },
        'login' => function ($auth, array $user): void {
            $auth->app->session->regenerate(true);   // session fixation defence
            $auth->app->session->set('_auth_id', $user[$auth->idColumn] ?? null);
            unset($user[$auth->passwordColumn]);
            $auth->_user = $user;
        },
        'logout' => function ($auth): void {
            $auth->app->session->set('_auth_id', null);
            $auth->app->session->regenerate(true);
            $auth->_user = null;
        },
        'user' => function ($auth): ?array {
            if (isset($auth->_user)) return $auth->_user;
            $id = $auth->app->session->get('_auth_id');
            if ($id === null) return $auth->_user = null;
            $row = $auth->app->db->fetchOne("SELECT * FROM {$auth->table} WHERE {$auth->idColumn} = :id", ['id' => $id]);
            if ($row !== null) unset($row[$auth->passwordColumn]);   // never hand the hash around
            return $auth->_user = $row;
        },
        'id' => fn ($auth) => $auth->app->session->get('_auth_id'),
        'check' => fn ($auth): bool => $auth->user() !== null,
    ]);
});

/** Guards routes/groups: $app->use('requireAuth') or ['requireAuth'] per route. */
$app->bind('requireAuth', function ($app, $next) {
    if ($app->auth->check()) { $next($app); return; }
    if ($app->wantsJson()) { $app->response->json(['error' => 'Unauthenticated'], 401); return; }
    if ($app->request->htmx->is) {
        // A 302 inside an HTMX swap would be followed transparently and the login page
        // injected into a fragment; HX-Redirect makes the browser navigate instead.
        $app->response->htmxRedirect((string) ($app->config->auth['loginPath'] ?? '/login'));
        return;
    }
    $app->response->redirect((string) ($app->config->auth['loginPath'] ?? '/login'));
});

$app->bind('csrf', function($app) {
    $app->session; // Force session initialization
    return new MagicObject([
        // Keyed tokens are capped: an attacker (or a chatty app) could otherwise grow
        // the session indefinitely, one entry per distinct key.
        'token' => function ($csrf, string $key = 'default'): string {
            $tokens = $_SESSION['_csrf_tokens'] ?? [];
            if (!isset($tokens[$key])) {
                $tokens[$key] = bin2hex(random_bytes(32));
                if (count($tokens) > 32) $tokens = array_slice($tokens, -32, null, true);
                $_SESSION['_csrf_tokens'] = $tokens;
            }
            return $tokens[$key];
        },
        'rotate' => function ($csrf, string $key = 'default'): string {
            $tokens = $_SESSION['_csrf_tokens'] ?? [];
            unset($tokens[$key]);
            $tokens[$key] = bin2hex(random_bytes(32));
            if (count($tokens) > 32) $tokens = array_slice($tokens, -32, null, true);
            $_SESSION['_csrf_tokens'] = $tokens;
            return $tokens[$key];
        },
        'verify' => function ($_, ?string $token, string $key = 'default'): bool {
            $stored = $_SESSION['_csrf_tokens'][$key] ?? '';

            // An empty stored token must never match an empty submitted token:
            // that would make every first POST in a session CSRF-exempt.
            return is_string($stored)
                && $stored !== ''
                && is_string($token)
                && $token !== ''
                && hash_equals($stored, $token);
        },
        // Markup helpers. RawHtml so the view layer emits them verbatim.
        'field' => function ($csrf, string $key = 'default'): RawHtml {
            return new RawHtml('<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrf->token($key), ENT_QUOTES) . '">');
        },
        /**
         * HTMX requests carry no form field, so without this every hx-post would be
         * rejected by the verifyCsrf middleware. Put it on <body>: it is inherited.
         */
        'htmxAttribute' => function ($csrf, string $key = 'default'): RawHtml {
            $json = json_encode(['X-CSRF-Token' => $csrf->token($key)], JSON_UNESCAPED_SLASHES);
            return new RawHtml("hx-headers='" . htmlspecialchars((string) $json, ENT_QUOTES) . "'");
        },
    ]);
});

/**
 * Filesystem cache. Writes are atomic (write to a temp file, then rename) so a
 * concurrent reader never sees a half-written entry. TTL 0 means "no expiry".
 */
$app->bind('cache', function ($app) {
    // Defaults under the app root, like every other path: the docroot is public/,
    // so this stays private.
    $dir = (string) ($app->config->cache['path'] ?? 'cache');
    if (!str_starts_with($dir, '/')) $dir = $app->root . '/' . $dir;
    $dir = rtrim($dir, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException("Cannot create cache directory: $dir");
    }
    $miss = new \stdClass();   // sentinel: distinguishes "absent" from a cached null

    // The read path cannot reconstruct objects, so reject them here instead of
    // writing an entry that would always miss (and make remember() recompute forever).
    $containsObject = function ($v) use (&$containsObject): bool {
        if (is_object($v)) return true;
        if (is_array($v)) foreach ($v as $item) if ($containsObject($item)) return true;
        return false;
    };
    // Nested objects also become __PHP_Incomplete_Class on read; check recursively.
    $containsIncomplete = function ($v) use (&$containsIncomplete): bool {
        if ($v instanceof \__PHP_Incomplete_Class) return true;
        if (is_array($v)) foreach ($v as $item) if ($containsIncomplete($item)) return true;
        return false;
    };

    return new MagicObject([
        'dir' => $dir,
        'path' => fn ($c, string $key): string => $c->dir . '/' . hash('sha256', $key) . '.cache',
        'get' => function ($c, string $key, mixed $default = null) use ($miss, $containsIncomplete) {
            $file = $c->path($key);
            if (!is_file($file)) return $default;
            $raw = @file_get_contents($file);
            if ($raw === false) return $default;
            // Never instantiate classes from a cache file: a writable cache would be a __wakeup payload.
            $entry = @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($entry) || !array_key_exists('expires', $entry)) return $default;
            if ($containsIncomplete($entry['value'] ?? null)) {
                @unlink($file);
                return $default;
            }
            if ($entry['expires'] !== 0 && $entry['expires'] < time()) { @unlink($file); return $default; }
            return $entry['value'];
        },
        'set' => function ($c, string $key, mixed $value, int $ttl = 0) use ($containsObject): bool {
            if ($containsObject($value)) {
                throw new \InvalidArgumentException(
                    "Cache value for '$key' contains an object; cache scalars and arrays only."
                );
            }
            $file = $c->path($key);
            $tmp = $file . '.' . bin2hex(random_bytes(6));
            $payload = serialize(['expires' => $ttl > 0 ? time() + $ttl : 0, 'value' => $value]);
            if (@file_put_contents($tmp, $payload, LOCK_EX) === false) return false;
            if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
            return true;
        },
        'has' => function ($c, string $key) use ($miss): bool { return $c->get($key, $miss) !== $miss; },
        'forget' => fn ($c, string $key): bool => !is_file($c->path($key)) || @unlink($c->path($key)),
        'flush' => function ($c): int {
            $n = 0;
            foreach ((glob($c->dir . '/*.cache') ?: []) as $file) { if (@unlink($file)) $n++; }
            return $n;
        },
        'remember' => function ($c, string $key, int $ttl, callable $producer) use ($miss) {
            $cached = $c->get($key, $miss);
            if ($cached !== $miss) return $cached;
            $value = $producer();
            $c->set($key, $value, $ttl);
            return $value;
        },
    ]);
});

$app->bind('request', function($app) {
    $getHeader = fn($_, $n) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string)$n))] ?? null;

    $contentType = function (): string {
        return strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    };
    $decodeJson = function ($req): mixed {
        $raw = (string) $req->body();
        if ($raw === '') return null;
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    };
    $parseInput = function ($req) use ($contentType, $decodeJson): array {
        // PHP fills $_POST for POST form/multipart bodies; for other verbs (and
        // JSON requests) the body has to be parsed from the raw stream.
        if ($_POST !== []) return $_POST;

        $type = $contentType();
        if (str_contains($type, 'application/json') || str_contains($type, '+json')) {
            $decoded = $decodeJson($req);
            return is_array($decoded) ? $decoded : [];
        }
        if (str_contains($type, 'application/x-www-form-urlencoded')) {
            $raw = (string) $req->body();
            if ($raw === '') return [];
            parse_str($raw, $form);
            return is_array($form) ? $form : [];
        }
        return [];
    };

    return new MagicObject([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/',
        'params' => [],
        'getQueryVar' => fn($_, string $n, mixed $d = null) => $_GET[$n] ?? $d,
        'input' => function ($req, ?string $key = null, mixed $default = null) use ($parseInput) {
            if (!array_key_exists('_input', $req->props())) $req->_input = $parseInput($req);
            $input = $req->_input;
            return $key === null ? $input : ($input[$key] ?? $default);
        },
        'json' => function ($req, ?string $key = null, mixed $default = null) use ($decodeJson) {
            $decoded = $decodeJson($req);
            if ($key === null) return $decoded;
            return is_array($decoded) ? ($decoded[$key] ?? $default) : $default;
        },
        'getPostVar' => fn($req, string $n, mixed $d = null) => $req->input($n, $d),
        'getHeader' => $getHeader,
        'body' => function () { static $c = null; if ($c === null) $c = file_get_contents('php://input'); return $c; },
        'htmx' => new MagicObject([
            'is' => ($getHeader(null, 'HX-Request') === 'true'),
            'target' => $getHeader(null, 'HX-Target'),
            'trigger' => $getHeader(null, 'HX-Trigger'),
            'triggerName' => $getHeader(null, 'HX-Trigger-Name'),
            'boosted' => ($getHeader(null, 'HX-Boosted') === 'true'),
            'currentUrl' => $getHeader(null, 'HX-Current-URL'),
        ])
    ]);
});

$app->bind('response', function($app) {
    $compileViewContext = function ($res, array $data) {
        // Shared globals resolve on first access, so declaring one costs nothing.
        $lazyGlobals = [];
        foreach (($res->app->props()['shared'] ?? []) as $name => $factory) {
            $lazyGlobals[$name] = fn () => $factory($res->app);
        }
        // Keep the session lazy too: rendering a public page must not start an anonymous session.
        $lazyGlobals['session'] = fn () => $res->app->session;
        return new ViewContext(array_merge(
            ['data' => $data],
            [
                'config' => $res->app->config,
                'request' => $res->app->request,
                // RawHtml marks this as trusted markup: the view layer must not escape it.
                'partial' => fn ($_, string $n, array $d = []) => new RawHtml($res->partial($n, $d)),
                'dump' => fn ($_, ...$vars) => new RawHtml($res->app->dumpHtml(...$vars)),
                'registry' => $lazyGlobals,
            ]
        ));
    };

    // Views default to the app root: html.pages.home -> <root>/html/pages/home.php.
    // With the docroot at public/ that is already private; override with views.path
    // if templates live elsewhere.
    $viewRoot = (string) ($app->config->views['path'] ?? $app->root);
    if (!str_starts_with($viewRoot, '/')) $viewRoot = $app->root . '/' . $viewRoot;
    $viewRoot = rtrim($viewRoot, '/');

    $resolveTemplate = function ($name) use ($viewRoot) {
        $name = (string) $name;
        // Dot notation only: no slashes, no traversal, no absolute paths.
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException("Invalid template name: '$name'");
        }
        $path = $viewRoot . '/' . str_replace('.', '/', $name) . '.php';
        $real = realpath($path);
        $rootReal = realpath($viewRoot);
        if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Template not found: '$name' (looked in $path)");
        }
        return $real;
    };

    return new MagicObject([
        'app' => $app,
        'statusCode' => 200,
        'headers' => [],
        'layout' => null,
        'htmx' => new MagicObject([]),
        // Prevents a stray second render()/json()/redirect() from corrupting the output.
        'sent' => false,
        'oobFragments' => [],
        /**
         * Queues an out-of-band fragment, appended after the main body. The fragment's
         * root element must carry hx-swap-oob (e.g. <div id="cart" hx-swap-oob="true">),
         * which is how HTMX updates several regions from one response.
         */
        'oob' => function ($res, string $template, array $data = []): void {
            $queued = $res->oobFragments;
            $queued[] = ['template' => $template, 'data' => $data];
            $res->oobFragments = $queued;
        },
        'htmxRedirect' => function ($res, string $url): void {
            if ($res->sent) return;
            // Must be a 2xx: HTMX only acts on HX-Redirect for successful responses.
            $res->setStatusCode(200);
            $res->setHeader('HX-Redirect', $url);
            $res->sent = true;   // deliberately empty body
        },
        /**
         * Server-sent events. The producer receives an $emit callable which returns
         * false once the client has disconnected: while ($emit($data)) { ... }
         */
        'stream' => function ($res, callable $producer): void {
            if ($res->sent) return;
            $res->setHeader('Content-Type', 'text/event-stream');
            $res->setHeader('Cache-Control', 'no-cache');
            $res->setHeader('Connection', 'keep-alive');
            $res->setHeader('X-Accel-Buffering', 'no');   // stops nginx from buffering the stream
            @set_time_limit(0);
            // Under CLI the test harness owns the output buffer, so leave it alone there.
            if (PHP_SAPI !== 'cli') { while (ob_get_level() > 0) @ob_end_flush(); }
            $emit = function (string $data, ?string $event = null, ?string $id = null): bool {
                if ($event !== null) echo "event: $event\n";
                if ($id !== null) echo "id: $id\n";
                foreach (explode("\n", $data) as $line) echo "data: $line\n";
                echo "\n";
                if (PHP_SAPI !== 'cli') @flush();
                return connection_aborted() === 0;
            };
            $producer($emit, $res->app);
            $res->sent = true;
        },
        'setStatusCode' => function ($res, int $c) {
            $res->statusCode = $c;
            if (!headers_sent()) http_response_code($c);
        },
        'setHeader' => function ($_, string $n, string $v) {
            if (!headers_sent()) header("$n: $v");
        },
        'redirect' => function ($res, string $url, int $code = 302) {
            if ($res->sent) return;
            $res->setStatusCode($code); // was missing: a Location header with a 200 status
            $res->setHeader('Location', $url);
            $res->sent = true;
        },
        'json' => function ($res, mixed $d, int $c = 200) {
            if ($res->sent) return;
            $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
            $res->setStatusCode($c);
            $res->setHeader('Content-Type', 'application/json; charset=utf-8');
            echo $json;
            $res->sent = true;
        },
        'text' => function ($res, string $body, int $c = 200) {
            if ($res->sent) return;
            $res->setStatusCode($c);
            $res->setHeader('Content-Type', 'text/plain; charset=utf-8');
            echo $body;
            $res->sent = true;
        },
        'html' => function ($res, string $body, int $c = 200) {
            if ($res->sent) return;
            $res->setStatusCode($c);
            $res->setHeader('Content-Type', 'text/html; charset=utf-8');
            echo $body;
            $res->sent = true;
        },
        'partial' => function ($res, string $template, array $data = []) use ($compileViewContext, $resolveTemplate) {
            $view = $compileViewContext($res, $data);
            $level = ob_get_level();
            ob_start();
            try {
                include $resolveTemplate($template);
                return ob_get_clean();
            } catch (\Throwable $e) {
                while (ob_get_level() > $level) @ob_end_clean();
                throw $e;
            }
        },
        'render' => function ($res, string $template, array $data = [], int $statusCode = 200) use ($compileViewContext, $resolveTemplate) {
            if ($res->sent) return;
            $res->setStatusCode($statusCode);

            foreach ($res->htmx->props() as $key => $val) {
                $header = 'HX-' . str_replace(' ', '-', ucwords(str_replace(['_', '-'], ' ', (string)$key)));
                $res->setHeader($header, is_array($val) ? json_encode($val) : (string)$val);
            }

            $view = $compileViewContext($res, $data);
            
            $renderFile = function($path, $view, $slot = null) {
                include $path;
            };

            // Capture the whole response so a template/layout that throws cannot leak
            // partial markup into the error page.
            $level = ob_get_level();
            ob_start();
            try {
                ob_start();
                $renderFile($resolveTemplate($template), $view);
                $slot = ob_get_clean();

                if ($res->layout && !$res->app->request->htmx->is) {
                    $renderFile($resolveTemplate($res->layout), $view, $slot);
                } else {
                    echo $slot;
                }
                foreach ($res->oobFragments as $fragment) {
                    echo $res->partial($fragment['template'], $fragment['data']);
                }
            } catch (\Throwable $e) {
                while (ob_get_level() > $level) @ob_end_clean();
                throw $e;
            }
            $body = ob_get_clean();
            echo $body;
            $res->sent = true;
        }
    ]);
});

$app->bind('test', function($app) {
    return new TestSuite($app);
});

// --- DEFAULT VIEW GLOBALS ---
// Lazy: a template that never mentions $view->auth pays nothing for it.
$app->share('auth', fn ($app) => $app->auth);
$app->share('flash', fn ($app) => $app->flash);
$app->share('csrf', fn ($app) => $app->csrf);

// --- MIDDLEWARES ---

/**
 * Opt-in CSRF enforcement: $app->use('verifyCsrf') or per-route/group middleware.
 * The framework shipped token generation and verification but never enforced anything.
 */
$app->bind('verifyCsrf', function ($app, $next) {
    if (in_array($app->request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $token = $app->request->getPostVar('_csrf') ?? $app->request->getHeader('X-CSRF-Token');
        if (!$app->csrf->verify(is_string($token) ? $token : null)) {
            if ($app->wantsJson()) $app->response->json(['error' => 'Invalid CSRF token'], 403);
            else $app->response->text('403 Invalid CSRF token', 403);
            return;
        }
    }
    $next($app);
});

/** Opt-in request logging: $app->use('log'). */
$app->bind('log', function ($app, $next) {
    $start = microtime(true);
    $next($app);
    $ms = number_format((microtime(true) - $start) * 1000, 2);
    $app->logger("{$app->response->statusCode} ({$ms}ms)");
});

// The engine never dispatches: public/index.php (or the monad CLI) decides when to run.
App::attach($app);
return $app;