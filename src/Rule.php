<?php

namespace Phasync\Doctor;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;

/**
 * Base for a rule: a node visitor that reports findings for the files being scanned.
 *
 * Call-like patterns are collected with hit(): every site of one pattern in one function (or file)
 * becomes one finding, whose symbol is "<context>: <what>".
 */
abstract class Rule extends NodeVisitorAbstract
{
    public const ID       = '';
    public const ADVICE   = '';
    public const QUESTION = '';
    public const TAG      = 'scope-safe-reason';

    /** @var list<Finding> */
    public array $findings = [];
    /** State tagged @scope-safe, not reported. */
    public int $annotated = 0;
    /** State never written outside its initialisation, not reported. */
    public int $unwritten = 0;
    public Project $project;
    /** The file being scanned, as shown in findings. */
    protected string $file = '';
    /** The file being scanned, as a real path. */
    protected string $path = '';
    /** @var list<string> */
    protected array $lines = [];
    /** @var array<string, array{rule: string, severity: Severity, symbol: string, what: string, writes: list<array>, reads: list<array>}> */
    private array $hits = [];
    /** @var array<string, true> */
    private array $tagged = [];

    /** @param list<string> $lines the file's source lines */
    public function beginFile(string $file, string $path, array $lines): void
    {
        $this->file  = $file;
        $this->path  = $path;
        $this->lines = $lines;
    }

    /** @return array<string, string> advice text keyed by the rule ids this class reports */
    public function advice(): array
    {
        return [static::ID => static::ADVICE];
    }

    /** Called once after every file was scanned. */
    public function finish(): void
    {
        foreach ($this->hits as $hit) {
            [$message, $question, $options] = $this->describe($hit['rule'], $hit['what'], \count($hit['writes']) + \count($hit['reads']));
            $first                          = $hit['writes'][0] ?? $hit['reads'][0];
            $this->findings[]               = new Finding($hit['rule'], $hit['severity'], $first['file'], $first['line'], $hit['symbol'], $message, $hit['writes'], $hit['reads'], $question, $options, Project::GUIDE);
        }
        $this->hits = [];
    }

    /**
     * The message, question and options of a finding collected with hit().
     *
     * @return array{string, string, list<array{label: string, code: string}>}
     */
    protected function describe(string $rule, string $what, int $count): array
    {
        return [$what . ($count > 1 ? " ($count sites)" : ''), static::QUESTION, []];
    }

    /** Records one site of a pattern; all sites of one pattern in one context become one finding. */
    protected function hit(string $rule, Severity $severity, Node $node, string $what, bool $write = true): void
    {
        $symbol = $this->context($node) . ': ' . $what;
        $key    = $rule . "\0" . $symbol;
        $this->hits[$key] ??= ['rule' => $rule, 'severity' => $severity, 'symbol' => $symbol, 'what' => $what, 'writes' => [], 'reads' => []];
        if ($severity->rank() < $this->hits[$key]['severity']->rank()) {
            $this->hits[$key]['severity'] = $severity;
        }
        $this->hits[$key][$write ? 'writes' : 'reads'][] = $this->site($node);
    }

    /**
     * @param list<string>|null $lines
     *
     * @return array{file: string, line: int, code: string}
     */
    protected function site(Node $node, ?string $file = null, ?array $lines = null): array
    {
        $line = $node->getStartLine();
        $code = \trim(($lines ?? $this->lines)[$line - 1] ?? '');

        return ['file' => $file ?? $this->file, 'line' => $line, 'code' => \strlen($code) > 160 ? \substr($code, 0, 157) . '...' : $code];
    }

    /**
     * Where a node is: "Ns\Class::method()", "Ns\function()", "{closure} in <context>", or the file
     * for code outside any function.
     */
    protected function context(Node $node): string
    {
        for ($p = $node->getAttribute('parent'); $p; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\Stmt\ClassMethod) {
                return $this->className($p) . '::' . $p->name->toString() . '()';
            }
            if ($p instanceof Node\Stmt\Function_) {
                return $p->namespacedName->toString() . '()';
            }
            if ($p instanceof Node\Expr\Closure || $p instanceof Node\Expr\ArrowFunction) {
                return '{closure} in ' . $this->context($p);
            }
        }

        return $this->file;
    }

    /** The @scope-safe reason of the class a node is in, null when it is in none or the class is untagged. */
    protected function classTag(Node $node): ?string
    {
        for ($p = $node->getAttribute('parent'); $p; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\Stmt\ClassLike) {
                return $this->scopeSafe($p, '', report: false);
            }
        }

        return null;
    }

    /** The name of the class a node is in, "class@anonymous in <context>" for an anonymous class, null outside a class. */
    protected function className(Node $node): ?string
    {
        for ($p = $node->getAttribute('parent'); $p; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\Stmt\ClassLike) {
                return $p->namespacedName?->toString() ?? 'class@anonymous in ' . $this->context($p);
            }
        }

        return null;
    }

    /**
     * The reason of a `@scope-safe <reason>` tag in the node's comments: null without a tag. A tag
     * without a reason is reported as a finding of its own, and still counts as a tag.
     *
     * @param list<string>|null $lines
     */
    protected function scopeSafe(Node $node, string $symbol, ?string $file = null, ?array $lines = null, bool $report = true): ?string
    {
        foreach ($node->getComments() as $comment) {
            if (1 === \preg_match('/@scope-safe\b(.*)$/m', $comment->getText(), $m)) {
                $reason = \trim(\preg_replace('#\*/\s*$#', '', $m[1]));
                if ($report && '' === $reason && !isset($this->tagged[$symbol])) {
                    $this->tagged[$symbol] = true;
                    $site                  = $this->site($node, $file, $lines);
                    $this->findings[]      = new Finding(self::TAG, Severity::Warning, $site['file'], $site['line'], $symbol, "@scope-safe on $symbol has no reason", [$site], [],
                        'Why is this safe to share between requests? Write it after the tag.',
                        [['label' => 'Give the reason', 'code' => '/** @scope-safe <why every request may share this> */']], Project::GUIDE);
                }

                return $reason;
            }
        }

        return null;
    }

    /** Lower-cased name of a plain function call, or null for method calls, dynamic calls and namespaced functions. */
    protected function functionName(Node $node): ?string
    {
        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            return $node->name->toLowerString();
        }

        return null;
    }

    /** The function whose variable a node is: the nearest enclosing function, looking through arrow functions. */
    protected function ownFunction(Node $node): ?Node\FunctionLike
    {
        $p = $node->getAttribute('parent');
        if ($p instanceof Node\ClosureUse) {
            $p = $p->getAttribute('parent')->getAttribute('parent'); // `use ($x)` names the outer function's variable
        }
        for (; $p; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\FunctionLike && !$p instanceof Node\Expr\ArrowFunction) {
                return $p;
            }
        }

        return null;
    }

    /**
     * The calls of a plain function named $name (lower case) with arguments in the function a node
     * is in: a function that changes a setting and changes it back calls the setter twice.
     */
    protected function setterCalls(Node $node, string $name): int
    {
        $function = $this->ownFunction($node);

        return null === $function ? 0 : \count((new NodeFinder())->find($function, fn (Node $n) => $this->functionName($n) === $name && [] !== $n->args));
    }

    protected function insideFunction(Node $node): bool
    {
        for ($p = $node->getAttribute('parent'); $p; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\FunctionLike) {
                return true;
            }
        }

        return false;
    }
}
