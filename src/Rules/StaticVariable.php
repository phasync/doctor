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
use PhpParser\PrettyPrinter\Standard;

/** Reports `static $x` variables in functions, methods and closures that are assigned after their initialiser. */
final class StaticVariable extends Rule
{
    public const ID       = 'static-variable';
    public const QUESTION = Fixes::QUESTION;
    public const ADVICE   = 'A static variable lives as long as the worker, so every request it serves sees what the last one stored. Fine for a cache of results that are the same for every request; a bug when it holds data of one request or one user.';

    /** @var list<list<Node\Stmt\Static_>> */
    private array $stack = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\FunctionLike) {
            $this->stack[] = [];
        } elseif ($node instanceof Node\Stmt\Static_ && $this->stack) {
            $this->stack[\array_key_last($this->stack)][] = $node;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (!$node instanceof Node\FunctionLike) {
            return null;
        }
        foreach (\array_pop($this->stack) as $statement) {
            $text = \trim(\implode("\n", \array_slice($this->lines, $statement->getStartLine() - 1, $statement->getEndLine() - $statement->getStartLine() + 1)));
            foreach ($statement->vars as $var) {
                $this->report($node, $statement, $var, $text);
            }
        }

        return null;
    }

    private function report(Node\FunctionLike $function, Node\Stmt\Static_ $statement, Node\StaticVar $var, string $text): void
    {
        $name   = $var->var->name;
        $symbol = $this->context($var) . ': static $' . $name;
        if (null !== ($this->scopeSafe($statement, $symbol) ?? $this->classTag($statement))) {
            ++$this->annotated;

            return;
        }
        $writes = $reads = [];
        $uses   = (new NodeFinder())->find($function, fn (Node $n) => $n instanceof Node\Expr\Variable && $n->name === $name && $n !== $var->var && $this->ownFunction($n) === $function);
        foreach ($uses as $use) {
            $site = $this->site($use);
            if (Writes::isWrite($use)) {
                $writes[$site['line']] = $site;
            } else {
                $reads[$site['line']] = $site;
            }
        }
        if (!$writes) {
            ++$this->unwritten;

            return;
        }
        $default          = null === $var->default ? 'null' : (new Standard())->prettyPrintExpr($var->default);
        $this->findings[] = new Finding(self::ID, Severity::Warning, $this->file, $var->getStartLine(), $symbol,
            "$symbol is assigned after its initialiser", \array_values($writes), \array_values($reads), self::QUESTION,
            Fixes::staticVariable($name, $text, $default), Project::GUIDE);
    }
}
