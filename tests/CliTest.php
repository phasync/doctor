<?php

use Phasync\Doctor\Project;

function scratch(): string
{
    $dir = \sys_get_temp_dir() . '/phasync-doctor-test-' . \getmypid();
    if (!\is_dir($dir)) {
        \mkdir($dir);
    }

    return $dir;
}

afterAll(function () {
    foreach (\glob(scratch() . '/*') as $f) {
        \unlink($f);
    }
    \rmdir(scratch());
});

it('exits 1 when there is an error finding', function () {
    [$code, $out] = cli(FIXTURES . '/rules/exit.php');

    expect($code)->toBe(1)
        ->and($out)->toContain('exit [error] (3)')->toContain('Summary');
});

it('exits 0 when there are only warnings and info', function () {
    expect(cli(FIXTURES . '/rules/static-variable.php')[0])->toBe(0)
        ->and(cli(FIXTURES . '/rules/blocking-call.php')[0])->toBe(0);
});

it('exits 0 and says so when nothing is found', function () {
    [$code, $out] = cli(FIXTURES . '/rules/clean.php');

    expect($code)->toBe(0)->and($out)->toContain('No findings.');
});

it('exits 2 on usage errors', function () {
    expect(cli('--bogus')[0])->toBe(2)
        ->and(cli('--format=xml')[0])->toBe(2)
        ->and(cli('/does/not/exist')[0])->toBe(2)
        ->and(cli('--autoload=/does/not/exist.php')[0])->toBe(2)
        ->and(cli('--baseline=/does/not/exist.json', FIXTURES . '/rules/clean.php')[0])->toBe(2);
});

it('prints JSON with the documented schema', function () {
    [$code, $out] = cli('--format=json', FIXTURES . '/rules/static-variable.php');
    $doc          = \json_decode($out, true, flags: \JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and(\array_keys($doc))->toBe(['doctor_version', 'project', 'summary', 'findings'])
        ->and(\array_keys($doc['project']))->toBe(['root', 'framework', 'adapter', 'note', 'guide'])
        ->and(\array_keys($doc['summary']))->toBe(['files', 'errors', 'warnings', 'info', 'by_rule', 'annotated', 'unwritten', 'baselined', 'stale_baseline'])
        ->and($doc['summary']['warnings'])->toBe(4)
        ->and($doc['summary']['annotated'])->toBe(2)
        ->and(\array_keys($doc['findings'][0]))->toBe(['rule', 'severity', 'file', 'line', 'symbol', 'message', 'writes', 'reads', 'question', 'options', 'docs'])
        ->and(\array_keys($doc['findings'][0]['writes'][0]))->toBe(['file', 'line', 'code'])
        ->and(\array_keys($doc['findings'][0]['options'][0]))->toBe(['label', 'code']);
});

it('prints an empty findings list when nothing is found', function () {
    expect(\json_decode(cli('--format=json', FIXTURES . '/rules/clean.php')[1], true, flags: \JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('shows each rule once with advice, question and option labels, and a summary table', function () {
    [, $out] = cli(FIXTURES . '/rules/static-variable.php');

    expect(\substr_count($out, 'Does this hold data specific to one request'))->toBe(1)
        ->and($out)->toMatch('/static-variable\s+warning\s+4/')
        ->and($out)->toContain('annotated @scope-safe: 2');
});

it('lists a few sites per finding, and all of them and the option code with --verbose', function () {
    expect(cli(FIXTURES . '/rules/many.php')[1])->toContain('... and 7 more')
        ->and(cli('--verbose', FIXTURES . '/rules/many.php')[1])->not->toContain('... and');
    expect(cli('--verbose', FIXTURES . '/rules/static-variable.php')[1])->toContain('\Scope::getCurrent()');
});

it('detects the framework and adapter from composer.lock and says what that means', function () {
    $cake = Project::detect(FIXTURES . '/projects/cakephp/src');
    expect($cake->root)->toBe(\realpath(FIXTURES . '/projects/cakephp'))
        ->and($cake->framework)->toBe(['name' => 'CakePHP', 'package' => 'cakephp/cakephp', 'version' => '5.1.2'])
        ->and($cake->adapter)->toBe(['package' => 'phasync/swerve-cakephp', 'version' => 'v0.1.0', 'installed' => true, 'concurrency' => 'one-at-a-time'])
        ->and($cake->note())->toContain('one request at a time')->toContain('--workers');

    $laravel = Project::detect(FIXTURES . '/projects/laravel');
    expect($laravel->adapter)->toBe(['package' => 'phasync/swerve-laravel', 'version' => null, 'installed' => false, 'concurrency' => 'concurrent'])
        ->and($laravel->note())->toContain('composer require phasync/swerve-laravel');

    expect(Project::detect(FIXTURES . '/projects/slim')->framework['name'])->toBe('Slim')
        ->and(Project::detect(FIXTURES . '/projects/slim')->adapter['concurrency'])->toBe('concurrent')
        ->and(Project::detect(FIXTURES . '/projects/plain')->framework)->toBeNull();

    [, $out] = cli(FIXTURES . '/projects/cakephp');
    expect($out)->toContain('CakePHP 5.1.2')->toContain('phasync/swerve-cakephp')
        ->toContain('https://phasync.github.io/guides/concurrent-requests-in-your-application/');
});

it('generates a baseline keyed by rule and symbol, and hides what it accepts', function () {
    $file         = scratch() . '/baseline.json';
    [$code, $out] = cli('--generate-baseline', '--baseline=' . $file, FIXTURES . '/projects/plain');
    $doc          = \json_decode(\file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($out)->toContain('1 finding')
        ->and($doc['accepted'])->toBe([['rule' => 'static-property', 'symbol' => 'Acme\Counter::$hits', 'reason' => '']]);

    $json = \json_decode(cli('--format=json', '--baseline=' . $file, FIXTURES . '/projects/plain')[1], true, flags: \JSON_THROW_ON_ERROR);
    expect($json['findings'])->toBe([])
        ->and($json['summary']['baselined'])->toBe(1)
        ->and($json['summary']['stale_baseline'])->toBe(0);
});

it('keeps reasons when regenerating a baseline and counts stale entries', function () {
    $file = scratch() . '/stale.json';
    \file_put_contents($file, \json_encode(['accepted' => [
        ['rule' => 'static-property', 'symbol' => 'Acme\Counter::$hits', 'reason' => 'a counter for metrics'],
        ['rule' => 'static-property', 'symbol' => 'Acme\Gone::$x', 'reason' => ''],
    ]]));

    $json = \json_decode(cli('--format=json', '--baseline=' . $file, FIXTURES . '/projects/plain')[1], true, flags: \JSON_THROW_ON_ERROR);
    expect($json['summary']['stale_baseline'])->toBe(1);

    cli('--generate-baseline', '--baseline=' . $file, FIXTURES . '/projects/plain');
    expect(\json_decode(\file_get_contents($file), true)['accepted'])->toBe([
        ['rule' => 'static-property', 'symbol' => 'Acme\Counter::$hits', 'reason' => 'a counter for metrics'],
    ]);
});

it('reads phasync-doctor.json in the project root by default', function () {
    $dir = scratch();
    \file_put_contents("$dir/composer.json", '{}');
    \copy(FIXTURES . '/projects/plain/src/Counter.php', "$dir/Counter.php");
    expect(cli($dir)[1])->toContain('Acme\Counter::$hits');

    \file_put_contents("$dir/phasync-doctor.json", \json_encode(['accepted' => [['rule' => 'static-property', 'symbol' => 'Acme\Counter::$hits', 'reason' => 'x']]]));
    expect(cli($dir)[1])->toContain('No findings.')->toContain('accepted by baseline: 1');
});

it('runs as an executable with the documented exit codes', function () {
    $bin = \escapeshellarg(__DIR__ . '/../bin/phasync-doctor');
    \exec("php $bin " . \escapeshellarg(FIXTURES . '/rules/exit.php') . ' >/dev/null', $o, $withError);
    \exec("php $bin " . \escapeshellarg(FIXTURES . '/rules/blocking-call.php') . ' >/dev/null', $o, $clean);
    \exec("php $bin --nope >/dev/null 2>&1", $o, $usage);

    expect([$withError, $clean, $usage])->toBe([1, 0, 2]);
});
