<?php

namespace Phasync\Doctor;

/** Renders findings as text or JSON. */
final class Report
{
    private const SITES = 5;

    /**
     * @param list<Finding>         $findings
     * @param array<string, string> $advice   advice per rule id, in rule order
     *
     * @return list<array{string, Severity, list<Finding>}> findings grouped by rule and severity, most severe first
     */
    public static function group(array $findings, array $advice): array
    {
        $order  = \array_flip(\array_keys($advice));
        $groups = [];
        foreach ($findings as $f) {
            $groups[$f->rule . "\0" . $f->severity->value][] = $f;
        }
        \uasort($groups, static fn (array $a, array $b) => [$a[0]->severity->rank(), $order[$a[0]->rule]] <=> [$b[0]->severity->rank(), $order[$b[0]->rule]]);

        $out = [];
        foreach ($groups as $list) {
            \usort($list, static fn (Finding $a, Finding $b) => [$a->file, $a->line, $a->symbol] <=> [$b->file, $b->line, $b->symbol]);
            $out[] = [$list[0]->rule, $list[0]->severity, $list];
        }

        return $out;
    }

    /** @param list<Finding> $findings */
    public static function text(array $findings, Scanner $scanner, Baseline $baseline, bool $verbose): string
    {
        $project = $scanner->project;
        $out     = 'phasync-doctor ' . Cli::VERSION . ': scanned ' . \count($scanner->files) . ' files' . (null !== $project->root ? " in {$project->root}" : '') . "\n";
        if (null !== $project->framework) {
            $f    = $project->framework;
            $a    = $project->adapter;
            $out .= "{$f['name']}" . (null !== $f['version'] ? " {$f['version']}" : '') . " ({$f['package']}), adapter {$a['package']}" . ($a['installed'] ? ' ' . ($a['version'] ?? '') : ' (not installed)') . "\n";
        }
        $out .= self::indent($project->note(), '  ') . "\n  Guide: " . Project::GUIDE . "\n";

        $rows = [['rule', 'severity', 'count']];
        $seen = [];
        foreach (self::group($findings, $scanner->advice) as [$rule, $severity, $list]) {
            $out .= "\n$rule [{$severity->value}] (" . \count($list) . ")\n";
            if (!isset($seen[$rule])) {
                $seen[$rule] = true;
                $out .= self::indent($scanner->advice[$rule], '  ') . "\n";
                if ('' !== $list[0]->question && Severity::Info !== $severity) {
                    $out .= self::indent('Question: ' . $list[0]->question, '  ') . "\n";
                }
                if ($list[0]->options && Severity::Info !== $severity) {
                    $out .= "  Options:\n";
                    foreach ($list[0]->options as $i => $o) {
                        $out .= self::indent($o['label'], '    ' . ($i + 1) . '. ', '       ') . "\n";
                    }
                    if (!$verbose) {
                        $out .= "  (each finding's ready code: --verbose, or --format=json)\n";
                    }
                }
            }
            $out .= "\n";
            foreach ($list as $f) {
                $out .= self::finding($f, $verbose);
            }
            $rows[] = [$rule, $severity->value, (string) \count($list)];
        }

        $counts = "annotated @scope-safe: {$scanner->annotated}; never written after initialisation (not reported): {$scanner->unwritten}; accepted by baseline: {$baseline->matched} (stale entries: {$baseline->stale})\n";
        if (1 === \count($rows)) {
            return $out . "\nNo findings.\n" . $counts;
        }
        $w    = \max(\array_map(static fn ($r) => \strlen($r[0]), $rows));
        $out .= "\nSummary\n";
        foreach ($rows as $i => $r) {
            $out .= \sprintf("  %-{$w}s  %-8s  %5s\n", ...$r);
            if (0 === $i) {
                $out .= '  ' . \str_repeat('-', $w + 2 + 8 + 2 + 5) . "\n";
            }
        }

        return $out . '  ' . $counts;
    }

    /** @param list<Finding> $findings */
    public static function json(array $findings, Scanner $scanner, Baseline $baseline): string
    {
        $rows  = [];
        $count = ['error' => 0, 'warning' => 0, 'info' => 0];
        $by    = [];
        foreach (self::group($findings, $scanner->advice) as [$rule, $severity, $list]) {
            foreach ($list as $f) {
                $rows[] = $f->toArray();
                ++$count[$severity->value];
                $by[$rule] = ($by[$rule] ?? 0) + 1;
            }
        }
        $doc = [
            'doctor_version' => Cli::VERSION,
            'project'        => $scanner->project->toArray(),
            'summary'        => [
                'files'          => \count($scanner->files),
                'errors'         => $count['error'],
                'warnings'       => $count['warning'],
                'info'           => $count['info'],
                'by_rule'        => (object) $by,
                'annotated'      => $scanner->annotated,
                'unwritten'      => $scanner->unwritten,
                'baselined'      => $baseline->matched,
                'stale_baseline' => $baseline->stale,
            ],
            'findings' => $rows,
        ];

        return \json_encode($doc, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }

    private static function finding(Finding $f, bool $verbose): string
    {
        $out = "  {$f->symbol}  {$f->file}:{$f->line}\n" . self::indent($f->message, '    ') . "\n";
        foreach (['writes' => $f->writes, 'reads' => $f->reads] as $kind => $list) {
            if (!$list) {
                continue;
            }
            $out .= "    $kind (" . \count($list) . "):\n";
            foreach ($verbose ? $list : \array_slice($list, 0, self::SITES) as $s) {
                $out .= "      {$s['file']}:{$s['line']}  {$s['code']}\n";
            }
            if (!$verbose && \count($list) > self::SITES) {
                $out .= '      ... and ' . (\count($list) - self::SITES) . " more\n";
            }
        }
        if ($verbose) {
            foreach ($f->options as $i => $o) {
                $out .= '    option ' . ($i + 1) . ': ' . $o['label'] . "\n" . \preg_replace('/^/m', '      ', $o['code']) . "\n";
            }
        }

        return $out . "\n";
    }

    private static function indent(string $text, string $first, ?string $rest = null): string
    {
        $rest ??= $first;
        $lines = \explode("\n", \wordwrap($text, 100 - \strlen($first)));

        return $first . \implode("\n" . $rest, $lines);
    }
}
