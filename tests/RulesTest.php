<?php

use Phasync\Doctor\Finding;

const R = 'tests/Fixtures/rules/';

it('reports a static property written outside its declaration, with every write and read', function () {
    expect(summary('rules/static-property-writes.php'))->toBe([
        'static-property-writes.php:5 static-property warning',
        'static-property-writes.php:6 static-property warning',
        'static-property-writes.php:7 static-property warning',
        'static-property-writes.php:8 static-property warning',
        'static-property-writes.php:9 static-property warning',
        'static-property-writes.php:10 static-property warning',
        'static-property-writes.php:11 static-property warning',
        'static-property-writes.php:13 static-property warning',
        'static-property-writes.php:14 static-property warning',
    ]);
    $f = bySymbol('rules/static-property-writes.php')['W::$inc'];
    expect(lines($f->writes))->toBe([21, 31])
        ->and($f->writes[0])->toBe(['file' => R . 'static-property-writes.php', 'line' => 21, 'code' => '--static::$inc;'])
        ->and(lines(bySymbol('rules/static-property-writes.php')['W::$ref']->reads))->toBe([30]);
});

it('keeps quiet about a static property never written outside its declaration', function () {
    expect(bySymbol('rules/static-property-writes.php'))->not->toHaveKey('W::$read');
});

it('asks whether the state is request data and offers annotate, Scope-keyed and onScopeChange fixes', function () {
    $f = bySymbol('rules/static-property-writes.php')['W::$assign'];

    expect($f->question)->toBe('Does this hold data specific to one request or private to a user? If not, it is fine — annotate it.')
        ->and(\array_column($f->options, 'label'))->toHaveCount(3)
        ->and($f->options[0]['code'])->toContain('@scope-safe')->toContain('public static $assign')
        ->and($f->options[1]['code'])->toContain('\Scope::getCurrent()')->toContain('WeakMap')->toContain('assign')
        ->and($f->options[2]['code'])->toContain('phasync::onScopeChange')->toContain('$assign')
        ->and($f->options[2]['code'])->toContain('phasync only')
        ->and($f->docs)->toBe('https://phasync.github.io/guides/concurrent-requests-in-your-application/');
});

it('counts a write through an object held by a static property', function () {
    expect(\array_keys(bySymbol('rules/static-object-write.php')))->toBe(['O::$registry']);
});

it('does not report statics annotated @scope-safe, on the property or the class', function () {
    expect(summary('rules/static-annotated.php'))->toBe([
        'static-annotated.php:8 scope-safe-reason warning',
        'static-annotated.php:9 static-property warning',
    ])->and(bySymbol('rules/static-annotated.php', 'scope-safe-reason'))->toHaveKey('A::$bare');
});

it('reports only dynamic static property writes', function () {
    expect(summary('rules/static-dynamic.php'))->toBe(['static-dynamic.php:11 static-property-dynamic warning']);
});

it('resolves inherited static properties to the declaring class and reports them once', function () {
    expect(summary('inherit'))->toBe([
        'Base.php:7 static-property warning',
        'Child.php:7 static-property warning',
    ]);
    $base = bySymbol('inherit')['Inherit\Base::$shared'];
    expect($base->writes)->toHaveCount(4);
});

it('gives every class using a trait its own static property', function () {
    expect(\array_keys(bySymbol('rules/static-trait.php')))->toBe(['Beta::$n']);
    expect(\array_keys(bySymbol('rules/static-trait-write.php')))->toBe(['One::$hits', 'Two::$hits']);
});

it('resolves a write in a trait to the property of the class using it', function () {
    expect(summary('rules/static-trait-host.php'))->toBe(['static-trait-host.php:15 static-property warning'])
        ->and(\array_keys(bySymbol('rules/static-trait-host.php')))->toBe(['Model::$dispatcher']);
});

it('reports static variables that are assigned, and not constant ones or annotated ones', function () {
    expect(summary('rules/static-variable.php'))->toBe([
        'static-variable.php:3 static-variable warning',
        'static-variable.php:5 static-variable warning',
        'static-variable.php:6 static-variable warning',
        'static-variable.php:8 static-variable warning',
    ]);
    $f = bySymbol('rules/static-variable.php')['c(): static $m'];
    expect(lines($f->writes))->toBe([5])
        ->and(lines($f->reads))->toBe([5])
        ->and($f->options[1]['code'])->toContain('\Scope::getCurrent()')->toContain('$m = &');
});

it('reports a global written inside a function or through $GLOBALS, once per name', function () {
    expect(\array_keys(bySymbol('rules/global-variable.php', 'global-variable')))->toBe(['global $a', 'global $l', 'global $q', 'global $x']);
    $x = bySymbol('rules/global-variable.php')['global $x'];
    expect($x->line)->toBe(3)
        ->and(lines($x->writes))->toBe([3])
        ->and(lines($x->reads))->toBe([3, 16]);
    expect(lines(bySymbol('rules/global-variable.php')['global $a']->reads))->toBe([5]);
});

it('reports setlocale() with getters as reads, and recommends Locale::setDefault()', function () {
    $f = bySymbol('rules/process-setting.php', 'setlocale')[R . 'process-setting.php: setlocale()'];

    expect($f->severity->value)->toBe('warning')
        ->and(lines($f->writes))->toBe([4])
        ->and(lines($f->reads))->toBe([23])
        ->and($f->options[0]['code'])->toContain('Locale::setDefault');
    expect(summary('rules/setlocale-getter.php'))->toBe(['setlocale-getter.php:5 setlocale info']);
});

it('reports process-wide settings and writes to $_SERVER and $_ENV, not getters', function () {
    expect(\array_keys(bySymbol('rules/process-setting.php', 'process-setting')))->toBe(\array_map(static fn ($s) => R . 'process-setting.php: ' . $s, [
        '$_ENV', '$_SERVER', 'chdir()', 'error_reporting()', 'ignore_user_abort()', 'ini_set()', 'mb_internal_encoding()',
        'putenv()', 'set_include_path()', 'set_time_limit()', 'umask()',
    ]));
    $sev = \array_map(static fn (Finding $f) => $f->severity->value, bySymbol('rules/process-setting.php', 'process-setting'));
    expect($sev[R . 'process-setting.php: set_time_limit()'])->toBe('info')
        ->and($sev[R . 'process-setting.php: ignore_user_abort()'])->toBe('info')
        ->and($sev[R . 'process-setting.php: ini_set()'])->toBe('warning')
        ->and(lines(bySymbol('rules/process-setting.php')[R . 'process-setting.php: $_SERVER']->writes))->toBe([19]);
});

it('notes the timezone, Locale::setDefault() and $_REQUEST as kept per request, and ignores $_GET, $_POST, $_COOKIE, $_FILES', function () {
    expect(summary('rules/process-setting.php'))->not->toContain('process-setting.php:17 process-setting warning');
    $managed = bySymbol('rules/process-setting.php', 'scope-managed');
    expect(\array_keys($managed))->toBe(\array_map(static fn ($s) => R . 'process-setting.php: ' . $s, ['$_REQUEST', 'Locale::setDefault()', 'date_default_timezone_set()']))
        ->and(\array_unique(\array_map(static fn (Finding $f) => $f->severity->value, $managed)))->toBe([R . 'process-setting.php: $_REQUEST' => 'info']);
    foreach (findings('rules/process-setting.php') as $f) {
        expect(\array_merge(lines($f->writes), lines($f->reads)))->not->toContain(17)->not->toContain(18)->not->toContain(25)->not->toContain(26);
    }
});

it('reports $_SESSION and session functions per function', function () {
    $s = bySymbol('rules/session.php', 'session');
    expect(\array_keys($s))->toBe(['login(): session', 'user(): session'])
        ->and(lines($s['login(): session']->writes))->toBe([5, 6])
        ->and(lines($s['user(): session']->reads))->toBe([10])
        ->and($s['user(): session']->severity->value)->toBe('warning');
});

it('rates the session by the adapter: info where requests run one at a time', function () {
    expect(summary('projects/cakephp'))->toBe(['Session.php:5 session info'])
        ->and(summary('projects/laravel'))->toBe(['Session.php:5 session warning'])
        ->and(ofRule('projects/cakephp', 'session')[0]->message)->toContain('one at a time');
});

it('reports connections kept in a static, a global or a shared container definition', function () {
    $s = bySymbol('rules/shared-connection.php', 'shared-connection');
    expect(\array_keys($s))->toBe([
        'Db::$pdo', 'db(): static $m', 'global $mc', 'global $redis',
        R . 'shared-connection.php: definition PDO::class', R . 'shared-connection.php: singleton(\'db\')',
    ])
        ->and($s['Db::$pdo']->line)->toBe(6)
        ->and($s['Db::$pdo']->options[0]['code'])->toContain('phasync\Util\Pool')
        ->and($s['Db::$pdo']->message)->toContain('Concurrent operations on the same resource are not supported');
});

it('resolves a container definition\'s concrete class to a connection by reflection or the known list, and Laravel bind() with shared=true', function () {
    $s = bySymbol('rules/shared-connection-definitions.php', 'shared-connection');

    expect(\array_keys($s))->toBe([
        R . 'shared-connection-definitions.php: bind(\'db\')',
        R . "shared-connection-definitions.php: definition 'cache'",
        R . 'shared-connection-definitions.php: definition Yiisoft\Db\Connection\ConnectionInterface::class',
    ]);
});

it('does not report an unrelated class-string definition or a bind() without shared=true', function () {
    $s = bySymbol('rules/shared-connection-definitions.php', 'shared-connection');

    expect(\array_keys($s))->not->toContain(R . "shared-connection-definitions.php: definition 'logger'")
        ->and(\array_keys($s))->not->toContain(R . 'shared-connection-definitions.php: bind(\'session\')');
});

it('reports every occurrence of PDO::ATTR_PERSISTENT (array key, setAttribute, getAttribute, a bare reference), pg_pconnect(), a mysqli p: host, pfsockopen(), Redis::pconnect() and a Memcached persistent id', function () {
    $found = ofRule('rules/persistent-connection.php', 'persistent-connection');
    \usort($found, static fn (Finding $a, Finding $b) => $a->line <=> $b->line);

    expect(\array_map(static fn (Finding $f) => $f->line, $found))->toBe([5, 10, 15, 21, 28, 33, 38, 50, 60, 70, 76, 91, 102, 117])
        ->and(\array_map(static fn (Finding $f) => $f->severity->value, $found))->toBe([
            'warning', 'warning', 'warning', 'warning', 'info', 'warning', 'warning',
            'warning', 'warning', 'warning', 'warning', 'warning', 'warning', 'warning',
        ]);
});

it('does not report a plain PDO option, pg_connect(), a mysqli host without p:, fsockopen() or Redis::connect()', function () {
    foreach (ofRule('rules/persistent-connection.php', 'persistent-connection') as $f) {
        expect(\array_merge(lines($f->writes), lines($f->reads)))
            ->not->toContain(45)->not->toContain(55)->not->toContain(65)->not->toContain(84)->not->toContain(96)->not->toContain(110)->not->toContain(122);
    }
});

it('distinguishes PDO::ATTR_PERSISTENT => true/a string key/a runtime key/false, getAttribute() and a bare reference in severity and message', function () {
    $f = bySymbol('rules/persistent-connection.php', 'persistent-connection');

    expect($f['attrTrue(): PDO::ATTR_PERSISTENT => true']->message)->toContain('same DSN and user')
        ->and($f['attrTrue(): PDO::ATTR_PERSISTENT => true']->severity->value)->toBe('warning')
        ->and($f["attrKey(): PDO::ATTR_PERSISTENT => 'pool1'"]->message)->toContain('same key')
        ->and($f['attrDynamic(): PDO::ATTR_PERSISTENT => <computed at runtime>']->message)->toContain('computed at runtime')
        ->and($f['setAttrTrue(): setAttribute(PDO::ATTR_PERSISTENT, true)']->message)->toContain('same DSN and user')
        ->and($f['attrFalse(): PDO::ATTR_PERSISTENT => false']->severity->value)->toBe('info')
        ->and($f['attrFalse(): PDO::ATTR_PERSISTENT => false']->message)->toContain('turned off here')
        ->and($f['getAttr(): getAttribute(PDO::ATTR_PERSISTENT)']->severity->value)->toBe('warning')
        ->and($f['bareReference(): PDO::ATTR_PERSISTENT']->severity->value)->toBe('warning')
        ->and($f['attrTrue(): PDO::ATTR_PERSISTENT => true']->options[0]['code'])->toContain('phasync\Util\Pool');
});

it('resolves a definition through an aliased `use` import: Yii3\'s ConnectionInterface::class => [\'class\' => Connection::class, ...], with a persistent PDO nested inside it', function () {
    $s = bySymbol('rules/shared-connection-yii3-db.php', 'shared-connection');
    $p = bySymbol('rules/shared-connection-yii3-db.php', 'persistent-connection');

    expect(\array_keys($s))->toBe([R . 'shared-connection-yii3-db.php: definition Yiisoft\Db\Connection\ConnectionInterface::class'])
        ->and(\array_keys($p))->toBe([R . 'shared-connection-yii3-db.php: PDO::ATTR_PERSISTENT => true']);
});

it('flags output buffering functions but not echo, methods or other namespaces', function () {
    expect(summary('rules/output-buffering.php'))->toBe([
        'output-buffering.php:5 output-buffering warning',
        'output-buffering.php:6 output-buffering warning',
        'output-buffering.php:7 output-buffering warning',
    ]);
});

it('flags header(), setcookie() and http_response_code() with arguments', function () {
    expect(summary('rules/response-output.php'))->toBe([
        'response-output.php:3 response-output warning',
        'response-output.php:4 response-output warning',
        'response-output.php:5 response-output warning',
    ]);
});

it('flags exit, die and dd() but not methods named exit', function () {
    expect(summary('rules/exit.php'))->toBe([
        'exit.php:3 exit error',
        'exit.php:4 exit error',
        'exit.php:7 exit error',
    ]);
});

it('rates global handlers by where they are registered', function () {
    expect(summary('rules/global-handler.php'))->toBe([
        'global-handler.php:3 global-handler info',
        'global-handler.php:4 global-handler warning',
        'global-handler.php:9 global-handler warning',
        'global-handler.php:13 global-handler warning',
        'global-handler.php:16 global-handler info',
    ]);
});

it('rates a setting changed and restored in the same function as info', function () {
    expect(summary('rules/process-restored.php'))->toBe([
        'process-restored.php:3 process-setting info',
        'process-restored.php:4 process-setting info',
        'process-restored.php:5 process-setting warning',
        'process-restored.php:6 process-setting warning',
    ]);
});

it('notes blocking calls as info, saying they block the worker without ext-phasync', function () {
    expect(summary('rules/blocking-call.php'))->toBe([
        'blocking-call.php:3 blocking-call info',
        'blocking-call.php:4 blocking-call info',
        'blocking-call.php:7 blocking-call info',
        'blocking-call.php:9 blocking-call info',
        'blocking-call.php:10 blocking-call info',
        'blocking-call.php:11 blocking-call info',
        'blocking-call.php:12 blocking-call info',
        'blocking-call.php:13 blocking-call info',
    ])->and(findings('rules/blocking-call.php')[0]->message)->toContain('without ext-phasync');
});

it('collects repeated calls in one function into one finding', function () {
    $found = findings('rules/many.php');

    expect($found)->toHaveCount(1)->and($found[0]->writes)->toHaveCount(12);
});

it('notes services the framework resets between requests', function () {
    expect(\array_keys(bySymbol('rules/reset-service.php', 'reset-service')))->toBe(['App\Cart', 'App\CurrentRoute']);
});

it('reports a file that fails to parse as an error finding', function () {
    $found = findings('rules/syntax-error.php');

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('parse-error')
        ->and($found[0]->severity->value)->toBe('error');
});

it('skips vendor, node_modules and storage unless --include-vendor is given', function () {
    expect(summary('app'))->toBe(['a.php:1 exit error'])
        ->and(summary('app', true))->toBe(['a.php:1 exit error', 'b.php:1 exit error']);
});

it('resolves a static property declared in an unscanned parent through the target autoloader', function () {
    $found = (new Phasync\Doctor\Scanner(false, FIXTURES . '/autoload/vendor/autoload.php'))->scan([FIXTURES . '/autoload/src']);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('static-property')
        ->and($found[0]->symbol)->toBe('Lib\Base::$registry')
        ->and($found[0]->line)->toBe(11)
        ->and($found[0]->writes)->toHaveCount(2);
});

it('reports a write whose declaring class cannot be found', function () {
    expect(summary('rules/static-unresolved.php'))->toBe(['static-unresolved.php:7 static-property-dynamic warning']);
});
