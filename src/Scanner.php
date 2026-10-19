<?php

namespace Phasync\Doctor;

use Phasync\Doctor\Rules\BlockingCall;
use Phasync\Doctor\Rules\ExitRule;
use Phasync\Doctor\Rules\GlobalHandler;
use Phasync\Doctor\Rules\GlobalVariable;
use Phasync\Doctor\Rules\Output;
use Phasync\Doctor\Rules\PersistentConnection;
use Phasync\Doctor\Rules\ProcessSetting;
use Phasync\Doctor\Rules\ResetService;
use Phasync\Doctor\Rules\Session;
use Phasync\Doctor\Rules\SharedConnection;
use Phasync\Doctor\Rules\StaticProperty;
use Phasync\Doctor\Rules\StaticVariable;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/** Scans PHP files and collects the findings of all rules. */
final class Scanner
{
    public const PARSE_ERROR = 'parse-error';

    private const SKIPPED_DIRECTORIES = ['node_modules', 'storage', '.git', 'cache', 'writable', 'runtime', 'tmp'];

    /** @var array<string, string> advice text per rule id, in report order */
    public array $advice = [];
    /** @var list<string> files scanned */
    public array $files = [];
    /** State tagged @scope-safe, not reported. */
    public int $annotated = 0;
    /** Statics and globals never written outside their initialisation, not reported. */
    public int $unwritten = 0;

    public readonly Project $project;

    public function __construct(
        private readonly bool $includeVendor = false,
        private readonly ?string $autoload = null,
        ?Project $project = null,
    ) {
        $this->project = $project ?? new Project();
    }

    /**
     * @param list<string> $paths files or directories
     *
     * @return list<Finding>
     */
    public function scan(array $paths): array
    {
        $rules = [
            new StaticProperty($this->autoload), new StaticVariable(), new GlobalVariable(), new SharedConnection($this->autoload),
            new PersistentConnection(), new ProcessSetting(), new Session(), new Output(), new ExitRule(), new GlobalHandler(), new ResetService(), new BlockingCall(),
        ];
        $this->advice = [];
        foreach ($rules as $rule) {
            $rule->project = $this->project;
            $this->advice += $rule->advice();
        }
        $this->advice[Rule::TAG]          = 'A @scope-safe tag needs the reason why every request may share the state, for whoever reads it next.';
        $this->advice[self::PARSE_ERROR]  = 'The file could not be parsed, so none of its patterns are reported. Fix the syntax error, or exclude the file.';

        $traverser = new NodeTraverser(new NameResolver(), new ParentConnectingVisitor(), ...$rules);
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $findings  = [];
        foreach ($paths as $path) {
            foreach ($this->phpFiles($path) as $real) {
                $file          = $this->display($real);
                $this->files[] = $file;
                $source        = \file_get_contents($real);
                try {
                    $ast = $parser->parse($source);
                } catch (Error $e) {
                    $findings[] = new Finding(self::PARSE_ERROR, Severity::Error, $file, \max(1, $e->getStartLine()), $file, $e->getRawMessage());
                    continue;
                }
                $lines = \explode("\n", $source);
                foreach ($rules as $rule) {
                    $rule->beginFile($file, $real, $lines);
                }
                $traverser->traverse($ast);
            }
        }
        foreach ($rules as $rule) {
            $rule->finish();
            \array_push($findings, ...$rule->findings);
            $this->annotated += $rule->annotated;
            $this->unwritten += $rule->unwritten;
        }

        return $findings;
    }

    /** @return iterable<string> real paths */
    private function phpFiles(string $path): iterable
    {
        if (\is_file($path)) {
            yield \realpath($path);

            return;
        }
        $skipped = $this->includeVendor ? self::SKIPPED_DIRECTORIES : [...self::SKIPPED_DIRECTORIES, 'vendor'];
        $dev     = $this->project->devPaths;
        $it      = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f) => !\in_array(\realpath($f->getPathname()), $dev, true)
                && (!$f->isDir() || !\in_array($f->getFilename(), $skipped, true)),
        ));
        $files = [];
        foreach ($it as $f) {
            if ($f->isFile() && 'php' === $f->getExtension()) {
                $files[] = \realpath($f->getPathname());
            }
        }
        \sort($files);

        yield from $files;
    }

    /** A path relative to the project root, else to the working directory, else absolute. */
    private function display(string $real): string
    {
        foreach ([$this->project->root, \getcwd()] as $base) {
            if (null !== $base && false !== $base && \str_starts_with($real, $base . '/')) {
                return \substr($real, \strlen($base) + 1);
            }
        }

        return $real;
    }
}
