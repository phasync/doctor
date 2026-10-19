<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Finding;
use Phasync\Doctor\Fixes;
use Phasync\Doctor\Project;
use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use Phasync\Doctor\Writes;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Reports global variables written inside functions or through $GLOBALS, once per name. */
final class GlobalVariable extends Rule
{
    public const ID       = 'global-variable';
    public const QUESTION = Fixes::QUESTION;
    public const ADVICE   = 'A global variable is one value for the whole worker: every request it serves sees what the last one stored. Fine for configuration set once at boot; a bug when it holds data of one request or one user.';

    /** @var array<string, array{writes: array<string, array>, reads: array<string, array>, first: ?array, tag: ?string}> */
    private array $globals = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\Variable && 'GLOBALS' === $node->name) {
            $dim  = $node->getAttribute('parent');
            $name = $dim instanceof Node\Expr\ArrayDimFetch && $dim->var === $node && $dim->dim instanceof Node\Scalar\String_ ? $dim->dim->value : '...';
            if (!\in_array($name, ['_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST', '_SERVER', '_ENV', '_SESSION'], true)) {
                $g = &$this->entry($name);
                $this->add($g, $this->site($node), Writes::isWrite($node));
                unset($g);
            }
        }

        return null;
    }

    /** `global` statements are read when their function is left, once every node in it has its parent set. */
    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\FunctionLike) {
            foreach ((new NodeFinder())->findInstanceOf($node, Node\Stmt\Global_::class) as $statement) {
                if ($this->ownFunction($statement) === $node) {
                    $this->statement($statement, $node);
                }
            }
        }

        return null;
    }

    private function statement(Node\Stmt\Global_ $node, Node\FunctionLike $function): void
    {
        $tag = $this->scopeSafe($node, 'global $' . \implode(', $', \array_map(static fn ($v) => \is_string($v->name) ? $v->name : '...', $node->vars)));
        foreach ($node->vars as $var) {
            if (!$var instanceof Node\Expr\Variable || !\is_string($var->name)) {
                continue;
            }
            $name = $var->name;
            $g    = &$this->entry($name);
            $g['tag'] ??= $tag;
            $g['first'] ??= $this->site($node);
            $this->add($g, $this->site($node), false);
            $uses = (new NodeFinder())->find($function, fn (Node $n) => $n instanceof Node\Expr\Variable && $n->name === $name && !$n->getAttribute('parent') instanceof Node\Stmt\Global_ && $this->ownFunction($n) === $function);
            foreach ($uses as $use) {
                $this->add($g, $this->site($use), Writes::isWrite($use));
            }
            unset($g);
        }
    }

    public function finish(): void
    {
        parent::finish();
        \ksort($this->globals);
        foreach ($this->globals as $name => $g) {
            if (null !== $g['tag']) {
                ++$this->annotated;
            } elseif (!$g['writes']) {
                ++$this->unwritten;
            } else {
                $first            = $g['first'] ?? \reset($g['writes']);
                $symbol           = 'global $' . $name;
                $this->findings[] = new Finding(self::ID, Severity::Warning, $first['file'], $first['line'], $symbol,
                    "$symbol is written " . \count($g['writes']) . ' ' . (1 === \count($g['writes']) ? 'time' : 'times') . ' inside functions or through $GLOBALS',
                    \array_values($g['writes']), \array_values($g['reads']), self::QUESTION, Fixes::globalVariable($name), Project::GUIDE);
            }
        }
    }

    /** @return array{writes: array<string, array>, reads: array<string, array>, first: ?array, tag: ?string} */
    private function &entry(string $name): array
    {
        $this->globals[$name] ??= ['writes' => [], 'reads' => [], 'first' => null, 'tag' => null];

        return $this->globals[$name];
    }

    /** @param array{file: string, line: int, code: string} $site */
    private function add(array &$g, array $site, bool $write): void
    {
        $g[$write ? 'writes' : 'reads'][$site['file'] . ':' . $site['line']] = $site;
    }
}
