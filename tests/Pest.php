<?php

use Phasync\Doctor\Cli;
use Phasync\Doctor\Finding;
use Phasync\Doctor\Project;
use Phasync\Doctor\Scanner;

const FIXTURES = __DIR__ . '/Fixtures';

/** @return list<Finding> */
function findings(string $path, bool $vendor = false): array
{
    $path = FIXTURES . '/' . $path;

    return (new Scanner($vendor, null, Project::detect($path)))->scan([$path]);
}

/** @return list<Finding> the findings of one rule */
function ofRule(string $path, string $rule): array
{
    return \array_values(\array_filter(findings($path), static fn (Finding $f) => $f->rule === $rule));
}

/** @return list<string> "file:line rule severity" for each finding, sorted */
function summary(string $path, bool $vendor = false): array
{
    $found = findings($path, $vendor);
    \usort($found, static fn (Finding $a, Finding $b) => [\basename($a->file), $a->line, $a->rule] <=> [\basename($b->file), $b->line, $b->rule]);

    return \array_map(static fn (Finding $f) => \basename($f->file) . ':' . $f->line . ' ' . $f->rule . ' ' . $f->severity->value, $found);
}

/** @return array<string, Finding> findings keyed by symbol */
function bySymbol(string $path, ?string $rule = null): array
{
    $out = [];
    foreach (null === $rule ? findings($path) : ofRule($path, $rule) as $f) {
        $out[$f->symbol] = $f;
    }
    \ksort($out);

    return $out;
}

/**
 * @param list<array{file: string, line: int, code: string}> $sites
 *
 * @return list<int>
 */
function lines(array $sites): array
{
    return \array_map(static fn (array $s) => $s['line'], $sites);
}

/** @return array{int, string, string} exit code, stdout, stderr */
function cli(string ...$args): array
{
    $out  = \fopen('php://memory', 'w+');
    $err  = \fopen('php://memory', 'w+');
    $code = Cli::run($args, $out, $err);
    \rewind($out);
    \rewind($err);

    return [$code, \stream_get_contents($out), \stream_get_contents($err)];
}
