# phasync/doctor

Static scanner for PHP applications that will run under [Swerve](https://github.com/phasync/swerve) or another long-running [phasync](https://github.com/phasync/phasync) worker, where one process serves many requests, one after another or several at once. It finds state and settings that outlive a request or are shared by requests in flight, and reports each as a candidate: where it is, every site that writes and reads it, the question to answer about it, and ready code for each way to resolve it. It never modifies code.

## Install

```
composer require --dev phasync/doctor
```

Requires PHP 8.2 or newer.

## Usage

```
vendor/bin/phasync-doctor [path ...] [options]
```

| Option | |
| --- | --- |
| `path` | Files or directories to scan for `*.php`; default the current directory. Directories named `vendor`, `node_modules`, `storage`, `cache`, `writable`, `runtime`, `tmp` and `.git` are skipped. So are the project's `autoload-dev` paths from `composer.json` (test code a worker never loads), unless given as the path. |
| `--include-vendor` | Scan `vendor/` directories too. To check one package, give its directory as the path instead. |
| `--format=text\|json` | `text` (default): grouped by rule, advice and question once per rule, a summary table. `json`: the schema below. |
| `--verbose` | Text: every write and read site, and each finding's ready code. |
| `--project=DIR` | The application root. Default: the nearest directory above the first path, outside any `vendor/`, with a `composer.json` or `composer.lock`. |
| `--autoload=FILE` | The application's Composer `autoload.php`, read (never run) to find the class that declares an inherited static property. Default `<project>/vendor/autoload.php`. |
| `--baseline=FILE` | Accepted findings. Default `<project>/phasync-doctor.json` when it exists. |
| `--generate-baseline` | Write every current finding to the baseline file, keeping the reasons of entries already there, and exit 0. |
| `-h`, `--help` | Usage. |

Exit codes: `0` no error findings, `1` at least one error finding, `2` usage error or crash.

The output starts with the framework and swerve adapter found in `composer.lock`, and what that adapter does: swerve-laravel (a pool of applications), swerve-symfony (a pool of kernels) and swerve-psr15 (Slim, Mezzio, any PSR-15 stack) run requests concurrently in a worker; swerve-yii, swerve-codeigniter, swerve-cakephp, swerve-laminas (MVC) and swerve-spiral run one request at a time per worker behind a `phasync\Util\Synchronized` lock, so capacity scales with `--workers`. Either way, statics and settings outlive the request. See [Making your application safe for concurrent requests](https://phasync.github.io/guides/concurrent-requests-in-your-application/).

## Rules

| Rule | Severity | Reports |
| --- | --- | --- |
| `static-property` | warning | A static property written outside its declaration, resolved to the class that declares it (inherited and trait properties included; a write through an element or a property of the object it holds counts). |
| `static-property-dynamic` | warning | A static write whose class or property name is computed at runtime, or whose class cannot be found. |
| `static-variable` | warning | `static $x` in a function, method or closure, assigned after its initialiser. |
| `global-variable` | warning | A global written in a function (`global $x`) or through `$GLOBALS`, once per name. |
| `shared-connection` | warning | `new PDO`, `mysqli`, `SQLite3`, `Redis`, `Memcached`, Predis, MongoDB, AMQP (and `mysqli_connect()`, `pg_connect()` ...) stored in a static property, a static variable, a global, or a container definition that hands out one shared instance: `singleton()`, `instance()`, `set()`, `share()`, `setFactory()`, `setService()`, Laravel's `bind()` with `$shared = true`, a keyed definition array, `$container['db'] = fn ...`. Also a Yii3/yiisoft-di, PHP-DI or plain `'id' => Concrete::class` (or `['class' => Concrete::class, ...]`) definition map whose concrete class is a database or cache connection or wraps one — PDO and subclasses, `mysqli`, any `Yiisoft\Db\Connection\ConnectionInterface`, `Doctrine\DBAL\Connection`, `Illuminate\Database\Connection`, Predis, Redis, Memcached and similar — resolved by BetterReflection where the class is autoloadable, else a known-names list. |
| `persistent-connection` | warning | A connection the driver itself keeps open across requests, one per process and key, not one per object: `PDO::ATTR_PERSISTENT` (`true`, keyed by DSN and user; a string, keyed by that string; a runtime value, keyed by whatever it turns out to be), `pg_pconnect()`, a mysqli `p:` host prefix (`new mysqli()`, `mysqli_connect()`, `->real_connect()`), `pfsockopen()`, `Redis::pconnect()`/`popen()`, and `new Memcached($persistentId)`. One connection per request, or a per-request `phasync\Util\Pool`, built on a persistent connection still shares that one process-wide connection. |
| `setlocale` | warning; info for a getter | `setlocale()`. It is process-wide and Scope does not keep it per request; the advice is to move to `Locale::setDefault()`/`Locale::getDefault()` and intl formatters, which Scope keeps per request. |
| `process-setting` | warning; info | `ini_set()`, `putenv()`, `chdir()`, `set_include_path()`, `umask()`, `error_reporting()`, the `mb_*` encoding setters, `libxml_use_internal_errors()`, `mt_srand()`, writes to `$_SERVER` and `$_ENV`. Info: `set_time_limit()`, `ignore_user_abort()`, and a setter the same function sets back. |
| `scope-managed` | info | `date_default_timezone_set()`, `Locale::setDefault()`, `locale_set_default()`, writes to `$_REQUEST`: Scope keeps these per request. |
| `session` | warning; info under a one-at-a-time adapter | `$_SESSION` and `session_*()`, per function, with what the detected adapter does about the session. |
| `output-buffering` | warning; info under a one-at-a-time adapter | `ob_*()`, `flush()`, `output_add_rewrite_var()`. |
| `response-output` | warning | `header()`, `header_remove()`, `setcookie()`, `setrawcookie()`, `http_response_code($code)`: in a swerve worker the response is the framework's response object. |
| `exit` | error | `exit`, `die`, `dd()`: they end the worker and every request it serves. |
| `global-handler` | warning; info at file level or when the function restores it | `set_error_handler()`, `set_exception_handler()`, `register_shutdown_function()`, `register_tick_function()`, `spl_autoload_register()` |
| `reset-service` | info | Services the framework resets between requests, so they hold request state: classes implementing Symfony's `ResetInterface`, Yii definitions with a `reset` callback. |
| `blocking-call` | info | `sleep()`, `usleep()`, `flock()`, `curl_exec()`, `exec()` and similar, `new PDO`, `mysqli_*()`, `file_get_contents('http...')`: they block the worker without ext-phasync. |
| `scope-safe-reason` | warning | A `@scope-safe` tag without a reason. |
| `parse-error` | error | A file that could not be parsed. |

Not reported: writes to `$_GET`, `$_POST`, `$_COOKIE` and `$_FILES` (Scope keeps them per request), constants, and statics and globals never written after their initialisation (counted in the summary as `unwritten`).

### Statics and globals

A static or a global is fine unless it holds data of one request or private to one user. Each candidate asks *Does this hold data specific to one request or private to a user? If not, it is fine — annotate it*, and offers three options with code that uses the symbol's own name:

1. Keep it and annotate it with `@scope-safe`.
2. The proper fix: one value per request in a `WeakMap` keyed by `\Scope::getCurrent()`.
3. The simple fix: save and restore the value in `phasync::onScopeChange()`. It is phasync's own, not part of fiber-interop/scope, ext-scope or the [Scope RFC](https://wiki.php.net/rfc/scope), so an application relying on it runs on phasync only; it runs on every switch between requests, so it should swap only a few references; and it covers only state the application swaps, so statics inside libraries still leak.

## `@scope-safe`

A docblock or comment tag that marks state as safe to share between requests, with the reason after it:

```php
/** @scope-safe compiled routes, the same for every request */
private static array $routes = [];

/** @scope-safe registry filled at boot */
final class Registry { /* covers every static property and static variable of the class */ }

function patterns(): array
{
    // @scope-safe compiled patterns, the same for every request
    static $compiled = [];
}

function config(): array
{
    /** @scope-safe configuration loaded once at boot */
    global $config;
}
```

It applies to static properties, classes (all their statics), `static $x` statements and `global $x` statements; on a `global` statement it covers that global everywhere. Tagged state is not reported and is counted in the summary. A tag without a reason is reported as `scope-safe-reason`.

## Baseline

Code that cannot be annotated, such as a package in `vendor/`, is accepted in `phasync-doctor.json` at the project root, by rule and symbol (no line numbers, so it survives edits):

```json
{
    "doctor_version": "0.1.0",
    "accepted": [
        {"rule": "static-property", "symbol": "Cake\\Core\\Configure::$_values", "reason": "swerve-cakephp restores it for each request"}
    ]
}
```

`--generate-baseline` writes the current findings into it, keeping existing reasons. Accepted findings are not reported; the summary counts them, and the entries that matched nothing (`stale_baseline`).

## JSON

```json
{
    "doctor_version": "0.1.0",
    "project": {
        "root": "/srv/app",
        "framework": {"name": "CakePHP", "package": "cakephp/cakephp", "version": "5.1.2"},
        "adapter": {"package": "phasync/swerve-cakephp", "version": "0.1.0", "installed": true, "concurrency": "one-at-a-time"},
        "note": "swerve-cakephp runs one request at a time per worker ...",
        "guide": "https://phasync.github.io/guides/concurrent-requests-in-your-application/"
    },
    "summary": {
        "files": 120, "errors": 0, "warnings": 3, "info": 2,
        "by_rule": {"static-property": 2, "session": 1},
        "annotated": 4, "unwritten": 17, "baselined": 1, "stale_baseline": 0
    },
    "findings": [
        {
            "rule": "static-property",
            "severity": "warning",
            "file": "src/Tenant.php",
            "line": 7,
            "symbol": "App\\Tenant::$current",
            "message": "App\\Tenant::$current is written once outside its declaration",
            "writes": [{"file": "src/Middleware/TenantMiddleware.php", "line": 21, "code": "Tenant::$current = $id;"}],
            "reads": [{"file": "src/Repository.php", "line": 40, "code": "->where('tenant', Tenant::$current)"}],
            "question": "Does this hold data specific to one request or private to a user? If not, it is fine — annotate it.",
            "options": [{"label": "Keep it: ...", "code": "/** @scope-safe ... */"}],
            "docs": "https://phasync.github.io/guides/concurrent-requests-in-your-application/"
        }
    ]
}
```

- `framework` and `adapter` are `null` when not recognised; `adapter.concurrency` is `concurrent` or `one-at-a-time`, `adapter.installed` says whether the lock file has it.
- `symbol` is stable: `Class::$prop` for a static property, `global $name` for a global, `<context>: static $name` for a static variable, `<context>: <what>` for calls (`App\Mailer::send(): setlocale()`), where the context is `Class::method()`, `function()`, `{closure} in <context>`, or the file for code outside functions. `rule` and `symbol` together identify a finding in the baseline.
- `writes` are the sites that change the state or make the call; `reads` the sites that only read it. `file` is relative to the project root.
- `question` is empty and `options` empty for notes that need no action.
- Findings are ordered by severity, then rule, then file and line.

## For a coding agent

1. Run `vendor/bin/phasync-doctor --format=json` from the application root and read `project.note`: it says whether requests overlap in a worker or run one at a time.
2. Fix `error` findings (`exit`, `dd()`) first: they end the worker.
3. For each `warning`, read the `writes` and `reads`, and answer the `question` from the code. Apply one of the `options`: annotate state that is the same for every request, key request or user data by `\Scope::getCurrent()`; use `phasync::onScopeChange()` only where the code cannot be changed and the application will run on phasync only.
4. Findings in code you do not own go into the baseline with a reason; rerun until no warning remains.
5. `info` findings need no change; `blocking-call` asks only that ext-phasync is loaded in production.

## License

MIT
