<?php

namespace Phasync\Doctor;

/** Command line entry point. */
final class Cli
{
    public const VERSION = '0.1.1';

    private const USAGE = <<<'TXT'
        Usage: phasync-doctor [path ...] [options]

          path                 files or directories to scan (default: the current directory)
          --include-vendor     also scan vendor/ directories
          --format=text|json   output format (default: text)
          --verbose            text: every write and read site, and each finding's ready code
          --project=DIR        the application root (default: the nearest directory above the
                               first path with a composer.lock, else a composer.json)
          --autoload=FILE      the application's Composer autoload.php, read (never run) to find the
                               class declaring an inherited static property
                               (default: <project>/vendor/autoload.php)
          --baseline=FILE      accepted findings (default: <project>/phasync-doctor.json when present)
          --generate-baseline  write every current finding to the baseline file, keeping existing
                               reasons, and exit 0
          -h, --help           this help

        Exit codes: 0 no error findings, 1 at least one error finding, 2 usage error or crash.

        TXT;

    /**
     * @param list<string> $args arguments without the script name
     * @param resource     $out
     * @param resource     $err
     */
    public static function run(array $args, $out, $err): int
    {
        $paths    = [];
        $format   = 'text';
        $vendor   = $verbose = $generate = false;
        $autoload = $root = $baselineFile = null;
        foreach ($args as $arg) {
            if ('--include-vendor' === $arg) {
                $vendor = true;
            } elseif ('--verbose' === $arg) {
                $verbose = true;
            } elseif ('--generate-baseline' === $arg) {
                $generate = true;
            } elseif (\str_starts_with($arg, '--format=')) {
                $format = \substr($arg, 9);
            } elseif (\str_starts_with($arg, '--autoload=')) {
                $autoload = \substr($arg, 11);
            } elseif (\str_starts_with($arg, '--project=')) {
                $root = \substr($arg, 10);
            } elseif (\str_starts_with($arg, '--baseline=')) {
                $baselineFile = \substr($arg, 11);
            } elseif ('--help' === $arg || '-h' === $arg) {
                \fwrite($out, self::USAGE);

                return 0;
            } elseif (\str_starts_with($arg, '-')) {
                \fwrite($err, "Unknown option $arg\n" . self::USAGE);

                return 2;
            } else {
                $paths[] = $arg;
            }
        }
        $paths = $paths ?: ['.'];
        if (!\in_array($format, ['text', 'json'], true)) {
            \fwrite($err, "Unknown format $format\n" . self::USAGE);

            return 2;
        }
        foreach ($paths as $path) {
            if (!\file_exists($path)) {
                \fwrite($err, "Path not found: $path\n");

                return 2;
            }
        }
        if (null !== $root && !\is_dir($root)) {
            \fwrite($err, "Project directory not found: $root\n");

            return 2;
        }
        $project = Project::detect($root ?? $paths[0]);
        if (null !== $root && null === $project->root) {
            $project = new Project(\realpath($root));
        }

        $autoload ??= null !== $project->root && \is_file("{$project->root}/vendor/autoload.php") ? "{$project->root}/vendor/autoload.php" : null;
        if (null !== $autoload && !\is_file($autoload)) {
            \fwrite($err, "Autoload file not found: $autoload\n");

            return 2;
        }
        if (null !== $baselineFile && !$generate && !\is_file($baselineFile)) {
            \fwrite($err, "Baseline file not found: $baselineFile\n");

            return 2;
        }
        $baselineFile ??= null !== $project->root ? $project->root . '/' . Baseline::FILE : null;
        $baseline = Baseline::load($baselineFile);

        $scanner  = new Scanner($vendor, null !== $autoload ? \realpath($autoload) : null, $project);
        $findings = $scanner->scan($paths);

        if ($generate) {
            if (null === $baselineFile) {
                \fwrite($err, "No project root to put the baseline in: give --baseline=FILE\n");

                return 2;
            }
            $baseline->write($baselineFile, $findings);
            \fwrite($out, 'Wrote ' . \count($findings) . ' finding' . (1 === \count($findings) ? '' : 's') . " to $baselineFile\n");

            return 0;
        }

        $findings = $baseline->filter($findings);
        \fwrite($out, 'json' === $format ? Report::json($findings, $scanner, $baseline) : Report::text($findings, $scanner, $baseline, $verbose));

        foreach ($findings as $f) {
            if (Severity::Error === $f->severity) {
                return 1;
            }
        }

        return 0;
    }
}
