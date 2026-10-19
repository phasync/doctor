<?php

namespace Phasync\Doctor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Decides whether a variable-like node is written to, referenced or passed by reference. A write to
 * an element of it, or to a property of an object it holds, counts as a write to it.
 */
final class Writes
{
    public static function isWrite(Node $node): bool
    {
        while ((($p = $node->getAttribute('parent')) instanceof Expr\ArrayDimFetch || $p instanceof Expr\PropertyFetch) && $p->var === $node) {
            $node = $p;
        }
        $p = $node->getAttribute('parent');

        return match (true) {
            $p instanceof Expr\Assign, $p instanceof Expr\AssignOp => $p->var === $node,
            $p instanceof Expr\AssignRef                           => true,
            $p instanceof Expr\PreInc, $p instanceof Expr\PostInc,
            $p instanceof Expr\PreDec, $p instanceof Expr\PostDec   => true,
            $p instanceof Stmt\Unset_                               => true,
            $p instanceof Stmt\Foreach_                             => $p->valueVar === $node || $p->keyVar === $node || ($p->byRef && $p->expr === $node),
            $p instanceof Node\ArrayItem                            => $p->byRef || (($a = $p->getAttribute('parent')) instanceof Expr\List_ || $a instanceof Expr\Array_) && self::isWrite($a),
            $p instanceof Node\ClosureUse                           => $p->byRef,
            $p instanceof Node\Arg                                  => self::passedByReference($p),
            default                                                 => false,
        };
    }

    private static function passedByReference(Node\Arg $arg): bool
    {
        $call = $arg->getAttribute('parent');
        if (!$call instanceof Expr\FuncCall || !$call->name instanceof Node\Name || $arg->unpack) {
            return false;
        }
        $name = $call->name->toString();
        if (!\function_exists($name)) {
            return false;
        }
        $fn = new \ReflectionFunction($name);
        if (!$fn->isInternal()) {
            return false;
        }
        $params = $fn->getParameters();
        $pos    = \array_search($arg, $call->args, true);
        $param  = $params[$pos] ?? ($params && \end($params)->isVariadic() ? \end($params) : null);

        return $param && $param->isPassedByReference();
    }
}
