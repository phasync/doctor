<?php

namespace Phasync\Doctor\Rules;

use Composer\Autoload\ClassLoader;
use Phasync\Doctor\Finding;
use Phasync\Doctor\Fixes;
use Phasync\Doctor\Project;
use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use Phasync\Doctor\Writes;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\Exception\CircularReference;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflector\DefaultReflector;
use Roave\BetterReflection\Reflector\Exception\IdentifierNotFound;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\ComposerSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;

/**
 * Reports static properties written outside their declaration, resolved to the class that owns the
 * storage, with every write and read.
 */
final class StaticProperty extends Rule
{
    public const ID       = 'static-property';
    public const DYNAMIC  = 'static-property-dynamic';
    public const QUESTION = Fixes::QUESTION;
    public const ADVICE   = 'A static property is one value for the whole worker: every request it serves, at once or one after another, sees what the last one wrote. That is fine for state that is the same for every request (configuration, compiled routes, a cache of pure results). It is a bug when it holds data of one request or one user.';

    /** @var list<array{owner: string, trait: bool, name: string, file: string, line: int, declaration: string, visibility: string, type: ?string, default: string, tag: ?string}> */
    private array $decls = [];
    /** @var array<string, list<string>> */
    private array $uses = [];
    /** @var array<string, bool> */
    private array $isTrait = [];
    /** @var array<string, ?string> the @scope-safe reason of each class, null when untagged */
    private array $classTags = [];
    /** @var array<string, string> */
    private array $classFiles = [];
    /** @var list<array{?string, string, bool, array{file: string, line: int, code: string}}> context class, property, is write, site */
    private array $accesses = [];
    /** @var array<string, ?string> */
    private array $owners = [];
    /** @var array<string, ReflectionClass> */
    private array $classes = [];

    public function __construct(private readonly ?string $autoload = null)
    {
    }

    public function advice(): array
    {
        return [
            self::ID      => self::ADVICE,
            self::DYNAMIC => 'The class or property name of this write is computed at runtime, or its class cannot be found, so the doctor cannot tell which static property it changes.',
        ];
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike && null !== $node->namespacedName) {
            $name                    = $node->namespacedName->toString();
            $this->classFiles[$name] = $this->path;
            $this->isTrait[$name]    = $node instanceof Node\Stmt\Trait_;
            $this->classTags[$name]  = $this->scopeSafe($node, $name);
        } elseif ($node instanceof Node\Stmt\TraitUse && null !== ($class = $this->className($node))) {
            foreach ($node->traits as $trait) {
                $this->uses[$class][] = $trait->toString();
            }
        } elseif ($node instanceof Node\Stmt\Property && $node->isStatic() && null !== ($owner = $this->className($node)) && isset($this->isTrait[$owner])) {
            $declaration = \trim(\implode("\n", \array_slice($this->lines, $node->getStartLine() - 1, $node->getEndLine() - $node->getStartLine() + 1)));
            foreach ($node->props as $prop) {
                $name          = $prop->name->toString();
                $this->decls[] = [
                    'owner'       => $owner, 'trait' => $this->isTrait[$owner], 'name' => $name, 'file' => $this->file, 'line' => $prop->getStartLine(),
                    'declaration' => $declaration, 'visibility' => $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public'),
                    'type'        => self::type($node->type), 'default' => null === $prop->default ? 'null' : (new Standard())->prettyPrintExpr($prop->default),
                    'tag'         => $this->scopeSafe($node, $owner . '::$' . $name),
                ];
            }
        } elseif ($node instanceof Node\Expr\StaticPropertyFetch) {
            $this->access($node);
        }

        return null;
    }

    private function access(Node\Expr\StaticPropertyFetch $node): void
    {
        $write = Writes::isWrite($node);
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            if ($write) {
                $this->hit(self::DYNAMIC, Severity::Warning, $node, (new Standard())->prettyPrintExpr($node));
            }

            return;
        }
        $context = match ($node->class->toLowerString()) {
            'self', 'static' => $this->className($node),
            'parent'         => $this->parentName($node),
            default          => $node->class->toString(),
        };
        $this->accesses[] = [$context, $node->name->toString(), $write, $this->site($node)];
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        return ["dynamic static property write $what", 'Which static property does this write, and does it hold data of one request or one user?', []];
    }

    public function finish(): void
    {
        parent::finish();
        $reflector = $this->reflector();
        $users     = $this->traitUsers();

        /** @var array<string, array{decl: array, tag: ?string}> $entries */
        $entries = [];
        foreach ($this->decls as $decl) {
            $classes = $decl['trait'] ? ($users[$decl['owner']] ?? []) : [$decl['owner']];
            foreach ($classes ?: [$decl['owner']] as $class) {
                $tag                                     = $decl['tag'] ?? $this->classTags[$decl['owner']] ?? $this->classTags[$class] ?? null;
                $entries[$class . '::$' . $decl['name']] = ['decl' => $decl, 'tag' => $tag];
            }
        }

        /** @var array<string, array{writes: list<array>, reads: list<array>}> $sites per resolved property */
        $sites      = [];
        $unresolved = [];
        foreach ($this->accesses as [$context, $name, $write, $site]) {
            $classes = [];
            $impl    = null === $context ? null : $this->owner($reflector, $context, $name);
            if (null !== $impl) {
                $classes = ($this->isTrait[$impl] ?? false) ? (($users[$impl] ?? []) ?: [$impl]) : [$impl];
            } elseif ($this->isTrait[$context] ?? false) {
                // a trait writing a property its using classes declare
                foreach ($users[$context] ?? [] as $user) {
                    if (null !== ($owner = $this->owner($reflector, $user, $name))) {
                        $classes[$owner] = $owner;
                    }
                }
            }
            foreach ($classes as $class) {
                $sites[$class . '::$' . $name][$write ? 'writes' : 'reads'][] = $site;
            }
            if (!$classes && $write) {
                $unresolved[($context ?? 'the enclosing class') . '::$' . $name][] = $site;
            }
        }
        foreach ($unresolved as $symbol => $writes) {
            $this->findings[] = new Finding(self::DYNAMIC, Severity::Warning, $writes[0]['file'], $writes[0]['line'], $symbol, "cannot find the class declaring $symbol", $writes, [],
                'Which class declares this static property, and does it hold data of one request or one user?', [], Project::GUIDE);
        }

        foreach ($entries as $symbol => ['decl' => $d, 'tag' => $tag]) {
            if (null !== $tag) {
                ++$this->annotated;
            } elseif (!isset($sites[$symbol]['writes'])) {
                ++$this->unwritten;
            } else {
                $class            = \strstr($symbol, '::$', true);
                $via              = $d['trait'] ? " (from trait {$d['owner']})" : '';
                $writes           = $sites[$symbol]['writes'];
                $this->findings[] = new Finding(self::ID, Severity::Warning, $d['file'], $d['line'], $symbol,
                    "$symbol$via is written " . self::times(\count($writes)) . ' outside its declaration',
                    $writes, $sites[$symbol]['reads'] ?? [], self::QUESTION,
                    Fixes::staticProperty($class, $d['name'], $d['declaration'], $d['visibility'], $d['type'], $d['default']), Project::GUIDE);
            }
        }
        foreach ($sites as $symbol => $found) {
            if (!isset($entries[$symbol]) && isset($found['writes'])) {
                [$class, $name]   = \explode('::$', $symbol);
                $first            = $found['writes'][0];
                $this->findings[] = new Finding(self::ID, Severity::Warning, $first['file'], $first['line'], $symbol,
                    "$symbol is declared outside the scanned files and written " . self::times(\count($found['writes'])),
                    $found['writes'], $found['reads'] ?? [], self::QUESTION,
                    Fixes::staticProperty($class, $name, "static \$$name;", 'public', null, 'null'), Project::GUIDE);
            }
        }
    }

    private static function times(int $n): string
    {
        return 1 === $n ? 'once' : "$n times";
    }

    private static function type(?Node $type): ?string
    {
        return match (true) {
            null === $type                                => null,
            $type instanceof Node\NullableType            => '?' . self::type($type->type),
            $type instanceof Node\UnionType               => \implode('|', \array_map(self::type(...), $type->types)),
            $type instanceof Node\IntersectionType        => \implode('&', \array_map(self::type(...), $type->types)),
            $type instanceof Node\Name                    => '\\' . $type->toString(),
            default                                       => $type->toString(),
        };
    }

    private function parentName(Node $node): ?string
    {
        for ($p = $node->getAttribute('parent'); $p; $p = $p->getAttribute('parent')) {
            if ($p instanceof Node\Stmt\ClassLike) {
                return $p instanceof Node\Stmt\Class_ ? $p->extends?->toString() : null;
            }
        }

        return null;
    }

    private function owner(DefaultReflector $reflector, string $class, string $name): ?string
    {
        $key = $class . '::$' . $name;
        if (!\array_key_exists($key, $this->owners)) {
            try {
                $this->owners[$key] = ($this->classes[$class] ??= $reflector->reflectClass($class))->getProperty($name)?->getImplementingClass()->getName();
            } catch (IdentifierNotFound|CircularReference) {
                $this->owners[$key] = null;
            }
        }

        return $this->owners[$key];
    }

    /** @return array<string, list<string>> classes that use each trait, directly or through other traits */
    private function traitUsers(): array
    {
        $users = [];
        foreach (\array_keys($this->isTrait, true, true) as $trait) {
            $queue = [$trait];
            while ($queue) {
                $t = \array_shift($queue);
                foreach ($this->uses as $user => $traits) {
                    if (\in_array($t, $traits, true)) {
                        if ($this->isTrait[$user] ?? false) {
                            $queue[] = $user;
                        } else {
                            $users[$trait][$user] = $user;
                        }
                    }
                }
            }
            $users[$trait] = \array_values($users[$trait] ?? []);
        }

        return $users;
    }

    private function reflector(): DefaultReflector
    {
        $loader = new ClassLoader();
        if (null !== $this->autoload) {
            $dir = \dirname($this->autoload) . '/composer';
            if (\is_file("$dir/autoload_psr4.php")) {
                foreach (require "$dir/autoload_psr4.php" as $prefix => $paths) {
                    $loader->setPsr4($prefix, $paths);
                }
                foreach (require "$dir/autoload_namespaces.php" as $prefix => $paths) {
                    $loader->set($prefix, $paths);
                }
                $loader->addClassMap(require "$dir/autoload_classmap.php");
            }
        }
        $loader->addClassMap($this->classFiles);
        $better = new BetterReflection();

        return new DefaultReflector(new AggregateSourceLocator([
            new ComposerSourceLocator($loader, $better->astLocator()),
            new PhpInternalSourceLocator($better->astLocator(), $better->sourceStubber()),
        ]));
    }
}
