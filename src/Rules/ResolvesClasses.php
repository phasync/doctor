<?php

namespace Phasync\Doctor\Rules;

use Composer\Autoload\ClassLoader;
use PhpParser\Node;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\Exception\CircularReference;
use Roave\BetterReflection\Reflector\DefaultReflector;
use Roave\BetterReflection\Reflector\Exception\IdentifierNotFound;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\ComposerSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;

/**
 * Resolves whether a class extends or implements one of a set of base classes or interfaces, using
 * BetterReflection against the project's autoloader and the classes seen while scanning. The host
 * class must declare `protected ?string $autoload`.
 */
trait ResolvesClasses
{
    /** @var array<string, string> class name => file it was declared in, for scanned classes */
    private array $classFiles           = [];
    private ?DefaultReflector $resolver = null;

    private function trackClass(Node\Stmt\ClassLike $node): void
    {
        if (null !== $node->namespacedName) {
            $this->classFiles[$node->namespacedName->toString()] = $this->path;
        }
    }

    /**
     * @param list<string> $bases lower-cased, no leading backslash
     *
     * A class declared in vendor/ can extend or implement something outside the scanned files and
     * the project's own autoloader (a Composer plugin capability, an abstract base in a package not
     * required here); getParentClass() and getInterfaceNames() can themselves throw IdentifierNotFound
     * for such a class, not only reflectClass() for the starting class, so the whole walk is guarded
     */
    private function classExtendsOrImplements(string $class, array $bases): bool
    {
        try {
            $ref = ($this->resolver ??= $this->buildResolver())->reflectClass($class);
            for ($c = $ref; null !== $c; $c = $c->getParentClass()) {
                if (\in_array(\strtolower($c->getName()), $bases, true)) {
                    return true;
                }
            }
            foreach ($ref->getInterfaceNames() as $interface) {
                if (\in_array(\strtolower($interface), $bases, true)) {
                    return true;
                }
            }
        } catch (IdentifierNotFound|CircularReference) {
            return false;
        }

        return false;
    }

    private function buildResolver(): DefaultReflector
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
