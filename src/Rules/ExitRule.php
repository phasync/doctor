<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use PhpParser\Node;

/** Reports exit, die and dd(). */
final class ExitRule extends Rule
{
    public const ID       = 'exit';
    public const QUESTION = 'Can this run while the worker serves a request? Then throw or return a response instead.';
    public const ADVICE   = 'exit, die and dd() end the worker process, and every request it is serving at that moment with it. Return a response or throw instead.';

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\Exit_) {
            $this->hit(self::ID, Severity::Error, $node, Node\Expr\Exit_::KIND_DIE === $node->getAttribute('kind') ? 'die' : 'exit');
        } elseif ('dd' === $this->functionName($node)) {
            $this->hit(self::ID, Severity::Error, $node, 'dd()');
        }

        return null;
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        return ["$what ends the worker and the requests in flight" . ($count > 1 ? " ($count sites)" : ''), self::QUESTION, [
            ['label' => 'Throw instead, and let the framework turn it into a response', 'code' => "throw new \\RuntimeException('...');"],
            ['label' => 'Return a response instead', 'code' => 'return $response->withStatus(403);'],
        ]];
    }
}
