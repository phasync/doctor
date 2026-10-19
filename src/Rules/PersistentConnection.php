<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Reports connections the driver itself keeps open across requests, one per process and key, not
 * one per PDO/mysqli/Redis/Memcached object: every occurrence of PDO::ATTR_PERSISTENT (an array key,
 * setAttribute(), getAttribute(), a bare reference passed around), pg_pconnect(), a mysqli `p:` host
 * prefix, pfsockopen(), Redis::pconnect()/popen() and a Memcached persistent id.
 */
final class PersistentConnection extends Rule
{
    public const ID       = 'persistent-connection';
    public const QUESTION = 'Can this connection run without persistence, one per request or borrowed from a phasync\Util\Pool?';
    public const ADVICE   = 'A persistent connection (PDO::ATTR_PERSISTENT, pg_pconnect(), a mysqli `p:` host prefix, pfsockopen(), Redis::pconnect()/popen(), a Memcached persistent id) is one connection per process and key (DSN and user for PDO\'s `true`, the key itself otherwise), shared by every connection made with that same key anywhere in the process. A one-connection-per-request setup or a per-request phasync\Util\Pool built on it still shares that one process-wide connection. Use non-persistent connections, one per request or from phasync\Util\Pool, instead. PDO::ATTR_PERSISTENT is reported wherever it appears, even where its value is only false or not statically known, since the option being reachable at all is what matters.';

    public function enterNode(Node $node): null
    {
        if ($this->isAttrPersistent($node)) {
            $this->reportConstant($node);

            return null;
        }
        $what = $this->matchOtherForms($node);
        if (null !== $what) {
            $this->hit(self::ID, Severity::Warning, $node, $what);
        }

        return null;
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        $sites  = $count > 1 ? " ($count sites)" : '';
        $shared = match (true) {
            \str_contains($what, 'false')                                                        => 'is turned off here, but the option exists in this code: elsewhere it may read true, a key, or something computed at runtime, which still shares one connection per DSN/user or key, process-wide',
            \str_contains($what, 'true')                                                         => 'shares every connection to the same DSN and user in the process',
            \str_contains($what, '<computed at runtime>')                                        => 'a key computed at runtime: every connection made with that same key, anywhere in the process, shares it',
            'PDO::ATTR_PERSISTENT' === $what || \str_starts_with($what, 'getAttribute')          => 'may be enabled at runtime; if it is, it shares every connection to the same DSN and user, or the same key, process-wide',
            \str_contains($what, 'ATTR_PERSISTENT')                                              => 'shares every connection made with that same key, anywhere in the process',
            default                                                                              => 'shares every connection made with the same host, key or credentials in the process',
        };

        return ["$what $shared$sites", self::QUESTION, [
            ['label' => 'Use a non-persistent connection: one per request, or borrowed from a phasync\Util\Pool', 'code' => <<<'PHP'
                // once per worker; use a non-persistent connection as the factory
                $db = new \phasync\Util\Pool(fn () => new \PDO($dsn, $user, $password), 10);

                // per query or transaction; finish the transaction before releasing
                $rows = $db->use(fn (\PDO $pdo) => $pdo->query('SELECT ...')->fetchAll());
                PHP],
        ]];
    }

    /** Reports $node, a PDO::ATTR_PERSISTENT reference, by what holds it: an array key, setAttribute()/getAttribute(), or nothing recognised (a bare reference). */
    private function reportConstant(Node $node): void
    {
        $p = $node->getAttribute('parent');
        // a call argument is wrapped in an Arg node; the call itself is the Arg's parent, not $node's
        $call           = $p instanceof Node\Arg ? $p->getAttribute('parent') : null;
        $name           = $this->isCall($call) ? $call->name->toLowerString() : null;
        [$what, $false] = match (true) {
            $p instanceof Node\ArrayItem && $p->key === $node                    => $this->describeValue($p->value, 'PDO::ATTR_PERSISTENT => '),
            'setattribute' === $name && ($call->args[0] ?? null) === $p          => $this->describeValue($call->args[1]->value ?? null, '', 'setAttribute(PDO::ATTR_PERSISTENT, ', ')'),
            'getattribute' === $name && ($call->args[0] ?? null) === $p          => ['getAttribute(PDO::ATTR_PERSISTENT)', false],
            default                                                              => ['PDO::ATTR_PERSISTENT', false],
        };
        $this->hit(self::ID, $false ? Severity::Info : Severity::Warning, $node, $what);
    }

    /** @return array{0: string, 1: bool} the value as it reads in the message, and whether it is a literal `false` */
    private function describeValue(?Node $value, string $prefix, string $before = '', string $after = ''): array
    {
        return match (true) {
            $value instanceof Expr\ConstFetch && 'false' === $value->name->toLowerString() => ["$prefix$before" . 'false' . $after, true],
            $value instanceof Expr\ConstFetch && 'true' === $value->name->toLowerString()  => ["$prefix$before" . 'true' . $after, false],
            $value instanceof Node\Scalar\String_                                          => ["$prefix$before'" . $value->value . "'$after", false],
            default                                                                        => ["$prefix$before" . '<computed at runtime>' . $after, false],
        };
    }

    /** The "what" of one of the other persistent-connection forms, or null when $node is not one. */
    private function matchOtherForms(Node $node): ?string
    {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier) {
            $name = $node->name->toLowerString();
            if ('real_connect' === $name && $this->persistentHost($node->args[0]->value ?? null)) {
                return "real_connect('p:...')";
            }
            if (\in_array($name, ['pconnect', 'popen'], true)) {
                return $node->name->toString() . '()';
            }

            return null;
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            $class = $node->class->toLowerString();
            if ('mysqli' === $class && $this->persistentHost($node->args[0]->value ?? null)) {
                return "new mysqli('p:...')";
            }
            if ('memcached' === $class && $this->hasPersistentId($node->args[0]->value ?? null)) {
                return 'new Memcached($persistentId)';
            }

            return null;
        }
        $name = $this->functionName($node);

        return match (true) {
            'pg_pconnect' === $name                                                            => 'pg_pconnect()',
            'pfsockopen' === $name                                                             => 'pfsockopen()',
            'mysqli_connect' === $name && $this->persistentHost($node->args[0]->value ?? null) => "mysqli_connect('p:...')",
            default                                                                            => null,
        };
    }

    private function isAttrPersistent(Node $node): bool
    {
        return $node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && 'pdo' === $node->class->toLowerString()
            && $node->name instanceof Node\Identifier && 'attr_persistent' === $node->name->toLowerString();
    }

    /** True when $node is a method or static call with a known name. */
    private function isCall(?Node $node): bool
    {
        return ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier;
    }

    /** True when $node is a string literal host starting with the mysqli persistent prefix "p:". */
    private function persistentHost(?Node $node): bool
    {
        return $node instanceof Node\Scalar\String_ && \str_starts_with($node->value, 'p:');
    }

    /** True when a Memcached constructor argument names a persistent id (not omitted, null or ''). */
    private function hasPersistentId(?Node $node): bool
    {
        return match (true) {
            null === $node                                                              => false,
            $node instanceof Expr\ConstFetch && 'null' === $node->name->toLowerString() => false,
            $node instanceof Node\Scalar\String_ && '' === $node->value                 => false,
            default                                                                     => true,
        };
    }
}
