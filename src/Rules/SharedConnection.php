<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Finding;
use Phasync\Doctor\Project;
use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Reports connections created into a static property, a static variable, a global, or a container
 * definition that hands out one shared instance.
 */
final class SharedConnection extends Rule
{
    use ResolvesClasses;

    public const ID       = 'shared-connection';
    public const QUESTION = 'Can two requests in one worker use this connection at the same time?';
    public const ADVICE   = 'A connection kept in a static, a global or a shared container definition is one connection for every request of the worker. A connection serves one call at a time: with ext-phasync, a coroutine entering a pgsql or sqlite PDO, SQLite3, a PostgreSQL connection or Memcached while another coroutine is in a call on it gets a LogicException, "Concurrent operations on the same resource are not supported"; mysqli and pdo_mysql have no such guard, and overlapping use fails with "Commands out of sync" or worse. Transactions are per connection, so one request can commit or roll back another\'s.';

    private const CLASSES = [
        'pdo', 'mysqli', 'sqlite3', 'redis', 'rediscluster', 'memcached', 'memcache', 'predis\client', 'relay\relay',
        'mongodb\client', 'mongodb\driver\manager', 'amqpconnection', 'phpamqplib\connection\amqpstreamconnection',
    ];
    /** Base classes and interfaces whose implementors wrap a connection, checked by reflection when not in CLASSES. */
    private const BASE_CLASSES = [
        'pdo', 'yiisoft\db\connection\connectioninterface', 'doctrine\dbal\connection', 'illuminate\database\connection',
    ];
    /**
     * Definition keys that are themselves a connection contract: a definition keyed by one of these
     * is reported even when the concrete class cannot be resolved (not autoloadable, or not scanned).
     */
    private const KEY_INTERFACES = [
        'yiisoft\db\connection\connectioninterface', 'doctrine\dbal\connection', 'illuminate\database\connection',
        'illuminate\database\connectioninterface', 'predis\clientinterface',
    ];
    private const FUNCTIONS = ['mysqli_connect', 'pg_connect', 'pg_pconnect', 'oci_connect', 'sqlsrv_connect'];
    /** Container methods whose definition is shared: Laravel, PHP-DI, League, Laminas, Phalcon. */
    private const SHARING_METHODS = ['singleton', 'singletonif', 'instance', 'set', 'setshared', 'share', 'addshared', 'setfactory', 'setservice'];

    /** @var array<string, array{0: string, 1: list<array>}> what was created, and where, per storage symbol */
    private array $found = [];
    /**
     * Candidate definitions: symbol, the value's named class (if any), the key's named class (if
     * any), site. Resolved in finish(), once every file is scanned, so a class can be a declaration
     * seen in another file, and read in leaveNode(), once the array item's own key and value were
     * visited and their names resolved against `use` imports (NameResolver resolves a name when it
     * is entered, which for the key and value happens after the array item itself is entered).
     *
     * @var list<array{0: string, 1: ?string, 2: ?string, 3: array}>
     */
    private array $definitionCandidates = [];

    public function __construct(protected readonly ?string $autoload = null)
    {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->trackClass($node);

            return null;
        }
        $created = match (true) {
            $node instanceof Expr\New_ && $node->class instanceof Node\Name && \in_array($node->class->toLowerString(), self::CLASSES, true) => $node->class->toString(),
            \in_array($this->functionName($node), self::FUNCTIONS, true)                                                                     => $this->functionName($node) . '()',
            default                                                                                                                          => null,
        };
        if (null !== $created && null !== ($symbol = $this->storage($node))) {
            $this->found[$symbol][0]   = $created;
            $this->found[$symbol][1][] = $this->site($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\ArrayItem && null !== $node->key && null !== ($key = $this->key($node->key))
            && !($node->key instanceof Node\Scalar\String_ && 'class' === \strtolower($node->key->value))) {
            $concrete = $this->definitionClassName($node->value);
            $keyClass = $this->classConstName($node->key);
            if (null !== $concrete || null !== $keyClass) {
                $this->definitionCandidates[] = [$this->context($node) . ": definition $key", $concrete, $keyClass, $this->site($node)];
            }
        }

        return null;
    }

    /** The class named by `X::class`, or null for anything else. */
    private function classConstName(Node $node): ?string
    {
        return $node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && 'class' === $node->name->toLowerString()
            ? $node->class->toString() : null;
    }

    /** The class named by a definition value: `X::class` directly, or under a 'class' key of an array (Yii3). */
    private function definitionClassName(Expr $value): ?string
    {
        if (null !== ($class = $this->classConstName($value))) {
            return $class;
        }
        if ($value instanceof Expr\Array_) {
            foreach ($value->items as $item) {
                if (null !== $item && $item->key instanceof Node\Scalar\String_ && 'class' === \strtolower($item->key->value)) {
                    return $this->definitionClassName($item->value);
                }
            }
        }

        return null;
    }

    /** Whether $class is a database or cache connection, or wraps one: the known list, else BetterReflection. */
    private function isConnectionClass(string $class): bool
    {
        if (\in_array(\strtolower(\ltrim($class, '\\')), self::CLASSES, true)) {
            return true;
        }

        return $this->classExtendsOrImplements($class, self::BASE_CLASSES);
    }

    public function finish(): void
    {
        parent::finish();
        foreach ($this->definitionCandidates as [$symbol, $concrete, $keyClass, $site]) {
            $named = match (true) {
                null !== $concrete && $this->isConnectionClass($concrete)                                         => $concrete,
                null !== $keyClass && \in_array(\strtolower(\ltrim($keyClass, '\\')), self::KEY_INTERFACES, true) => $keyClass,
                default                                                                                           => null,
            };
            if (null !== $named) {
                $this->found[$symbol][0]   = $named;
                $this->found[$symbol][1][] = $site;
            }
        }
        foreach ($this->found as $symbol => [$created, $sites]) {
            $this->findings[] = new Finding(self::ID, Severity::Warning, $sites[0]['file'], $sites[0]['line'], $symbol,
                "a $created connection kept in $symbol is shared by every request of the worker. " . \substr(self::ADVICE, \strpos(self::ADVICE, 'A connection serves')),
                $sites, [], self::QUESTION, [
                    ['label' => 'Borrow a connection from a phasync\Util\Pool for each query or transaction', 'code' => <<<'PHP'
                        // once per worker
                        $db = new \phasync\Util\Pool(fn () => new \PDO($dsn, $user, $password), 10);

                        // per query or transaction; finish the transaction before releasing
                        $rows = $db->use(fn (\PDO $pdo) => $pdo->query('SELECT ...')->fetchAll());
                        PHP],
                    ['label' => 'One connection per request, keyed by \Scope::getCurrent()', 'code' => <<<'PHP'
                        function db(): \PDO
                        {
                            // @scope-safe one connection per request, keyed by Scope
                            static $byScope = new \WeakMap();

                            return $byScope[\Scope::getCurrent()] ??= new \PDO($dsn, $user, $password);
                        }
                        PHP],
                ], Project::GUIDE);
        }
    }

    /** Where the connection created at $node is kept, when that is somewhere shared; null otherwise. */
    private function storage(Node $node): ?string
    {
        $p = $node->getAttribute('parent');
        if (($p instanceof Expr\Assign || $p instanceof Expr\AssignRef || $p instanceof Expr\AssignOp\Coalesce) && $p->expr === $node) {
            return $this->target($p->var, $node);
        }
        if ($p instanceof Node\Arg && null !== ($symbol = $this->definition($p))) {
            return $symbol;
        }
        for ($f = $p; $f; $f = $f->getAttribute('parent')) {
            if ($f instanceof Expr\Closure || $f instanceof Expr\ArrowFunction) {
                $outer = $f->getAttribute('parent');
                if ($outer instanceof Node\Arg) {
                    return $this->definition($outer);
                }
                if ($outer instanceof Node\ArrayItem && null !== ($key = $this->key($outer->key))) {
                    return $this->context($outer) . ": definition $key";
                }
                if ($outer instanceof Expr\Assign && $outer->var instanceof Expr\ArrayDimFetch && null !== ($key = $this->key($outer->var->dim))) {
                    return $this->context($outer) . ': $' . (\is_string($outer->var->var->name ?? null) ? $outer->var->var->name : '...') . "[$key]";
                }

                return null;
            }
            if ($f instanceof Node\FunctionLike || $f instanceof Node\Stmt) {
                if ($f instanceof Node\Stmt\Return_ || $f instanceof Node\Stmt\Expression) {
                    continue;
                }

                return null;
            }
        }

        return null;
    }

    private function target(Expr $var, Node $node): ?string
    {
        if ($var instanceof Expr\StaticPropertyFetch && $var->class instanceof Node\Name && $var->name instanceof Node\Identifier) {
            $class = \in_array($var->class->toLowerString(), ['self', 'static'], true) ? $this->className($node) : $var->class->toString();

            return null === $class || $this->propertyTagged($node, $var->name->toString()) ? null : $class . '::$' . $var->name->toString();
        }
        if ($var instanceof Expr\ArrayDimFetch && $var->var instanceof Expr\Variable && 'GLOBALS' === $var->var->name && $var->dim instanceof Node\Scalar\String_) {
            return 'global $' . $var->dim->value;
        }
        if (!$var instanceof Expr\Variable || !\is_string($var->name) || null === ($function = $this->ownFunction($node))) {
            return null;
        }
        foreach ($function->getStmts() ?? [] as $stmt) {
            foreach ($stmt instanceof Node\Stmt\Static_ || $stmt instanceof Node\Stmt\Global_ ? $stmt->vars : [] as $v) {
                $name = $v instanceof Node\StaticVar ? $v->var->name : ($v instanceof Expr\Variable ? $v->name : null);
                if ($name === $var->name) {
                    if (null !== $this->scopeSafe($stmt, '', report: false) || null !== $this->classTag($stmt)) {
                        return null;
                    }

                    return $stmt instanceof Node\Stmt\Static_ ? $this->context($node) . ': static $' . $name : 'global $' . $name;
                }
            }
        }

        return null;
    }

    /** "<context>: singleton('db')" when the argument belongs to a container method that shares what it defines. */
    private function definition(Node\Arg $arg): ?string
    {
        $call = $arg->getAttribute('parent');
        if (!($call instanceof Expr\MethodCall || $call instanceof Expr\StaticCall) || !$call->name instanceof Node\Identifier) {
            return null;
        }
        $name = $call->name->toLowerString();
        if (!\in_array($name, self::SHARING_METHODS, true) && !('bind' === $name && $this->sharedBind($call))) {
            return null;
        }
        $first = $call->args[0] ?? null;
        $key   = $first instanceof Node\Arg && $first !== $arg ? $this->key($first->value) : null;

        return $this->context($call) . ': ' . $call->name->toString() . '(' . ($key ?? '...') . ')';
    }

    /** True for Laravel's bind($abstract, $concrete, $shared): a literal `true` third argument. */
    private function sharedBind(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $third = $call->args[2] ?? null;

        return $third instanceof Node\Arg && $third->value instanceof Expr\ConstFetch && 'true' === $third->value->name->toLowerString();
    }

    private function key(?Node $key): ?string
    {
        return match (true) {
            $key instanceof Node\Scalar\String_                                                                               => "'" . $key->value . "'",
            $key instanceof Expr\ClassConstFetch && $key->class instanceof Node\Name && $key->name instanceof Node\Identifier => $key->class->toString() . '::' . $key->name->toString(),
            default                                                                                                           => null,
        };
    }

    private function propertyTagged(Node $node, string $name): bool
    {
        for ($p = $node->getAttribute('parent'); $p; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\Stmt\ClassLike) {
                foreach ($p->getProperties() as $property) {
                    foreach ($property->props as $prop) {
                        if ($prop->name->toString() === $name) {
                            return null !== $this->scopeSafe($property, '', report: false) || null !== $this->scopeSafe($p, '', report: false);
                        }
                    }
                }

                return null !== $this->scopeSafe($p, '', report: false);
            }
        }

        return false;
    }
}
