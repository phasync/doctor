<?php

namespace Phasync\Doctor;

/** The question and ready code for state shared by every request of a worker: statics and globals. */
final class Fixes
{
    public const QUESTION = 'Does this hold data specific to one request or private to a user? If not, it is fine — annotate it.';

    private const CAVEATS = <<<'TXT'
        // phasync only: onScopeChange() is not part of fiber-interop/scope, ext-scope or the Scope RFC,
        // so an application relying on it runs on phasync only. It runs on every switch between
        // requests: swap only a few references. It covers only state you swap here; statics inside
        // libraries still leak between requests. Register it once at boot.
        TXT;

    /**
     * Options for a static property.
     *
     * @param string $declaration the declaration as written, e.g. "private static array $cache = [];"
     *
     * @return list<array{label: string, code: string}>
     */
    public static function staticProperty(string $class, string $name, string $declaration, string $visibility, ?string $type, string $default): array
    {
        $short = \substr(\strrchr('\\' . $class, '\\'), 1);
        $ret   = null === $type ? 'mixed' : $type;

        return [
            self::annotate("/** @scope-safe <why every request may share it> */\n$declaration"),
            ['label' => 'Proper fix: keep one value per request, keyed by \Scope::getCurrent() in a WeakMap', 'code' => <<<PHP
                // In $class, instead of: $declaration
                /** @scope-safe one value per request, keyed by Scope */
                $visibility static \\WeakMap \${$name}ByScope;

                /** This request's $short::\$$name; write through a reference: \$v = &self::$name(); \$v[] = 1; */
                $visibility static function &$name(): $ret
                {
                    self::\${$name}ByScope ??= new \\WeakMap();
                    \$scope = \\Scope::getCurrent();
                    if (!isset(self::\${$name}ByScope[\$scope])) {
                        self::\${$name}ByScope[\$scope] = $default;
                    }

                    return self::\${$name}ByScope[\$scope];
                }
                PHP],
            ['label' => 'Simple fix: save and restore it per request in phasync::onScopeChange()', 'code' => self::CAVEATS . "\n" . <<<PHP
                // In $class, called once at boot (a method of the class, so it reaches a non-public property):
                public static function keepPerRequest(): void
                {
                    \$saved = new \\WeakMap();
                    \\phasync::onScopeChange(static function (\\Scope \$new, ?\\Scope \$old) use (\$saved) {
                        if (null !== \$old) {
                            \$saved[\$old] = self::\$$name;
                        }
                        self::\$$name = \$saved[\$new] ?? $default;
                    });
                }
                PHP],
        ];
    }

    /**
     * Options for a `static $name` variable in a function.
     *
     * @return list<array{label: string, code: string}>
     */
    public static function staticVariable(string $name, string $statement, string $default): array
    {
        return [
            self::annotate("// @scope-safe <why every request may share it>\n$statement"),
            ['label' => 'Proper fix: keep one value per request, keyed by \Scope::getCurrent() in a WeakMap', 'code' => <<<PHP
                // Instead of: $statement
                // @scope-safe one value per request, keyed by Scope
                static \${$name}ByScope = new \\WeakMap();
                \$scope = \\Scope::getCurrent();
                if (!isset(\${$name}ByScope[\$scope])) {
                    \${$name}ByScope[\$scope] = $default;
                }
                \$$name = &\${$name}ByScope[\$scope];
                PHP],
            ['label' => 'Simple fix: not available', 'code' => '// A static variable cannot be reached from outside its function, so phasync::onScopeChange() cannot swap it: use the proper fix.'],
        ];
    }

    /**
     * Options for a global variable.
     *
     * @return list<array{label: string, code: string}>
     */
    public static function globalVariable(string $name): array
    {
        return [
            self::annotate("/** @scope-safe <why every request may share it> */\nglobal \$$name;"),
            ['label' => 'Proper fix: keep one value per request, keyed by \Scope::getCurrent() in a WeakMap', 'code' => <<<PHP
                // Once, next to the code that uses it:
                function &request_global_$name(): mixed
                {
                    // @scope-safe one value per request, keyed by Scope
                    static \$byScope = new \\WeakMap();
                    \$scope = \\Scope::getCurrent();
                    if (!isset(\$byScope[\$scope])) {
                        \$byScope[\$scope] = null;
                    }

                    return \$byScope[\$scope];
                }

                // Then, wherever the code says `global \$$name;` (and instead of \$GLOBALS['$name']):
                \$$name = &request_global_$name();
                PHP],
            ['label' => 'Simple fix: save and restore it per request in phasync::onScopeChange()', 'code' => self::CAVEATS . "\n" . <<<PHP
                \$saved = new \\WeakMap();
                \\phasync::onScopeChange(static function (\\Scope \$new, ?\\Scope \$old) use (\$saved) {
                    if (null !== \$old) {
                        \$saved[\$old] = \$GLOBALS['$name'] ?? null;
                    }
                    \$GLOBALS['$name'] = \$saved[\$new] ?? null;
                });
                PHP],
        ];
    }

    /**
     * The phasync::onScopeChange() fallback for a process-wide setting, with its caveats.
     *
     * @return array{label: string, code: string}
     */
    public static function swapSetting(string $label, string $save, string $restore): array
    {
        return ['label' => $label, 'code' => self::CAVEATS . "\n" . <<<PHP
            \$saved = new \\WeakMap();
            \\phasync::onScopeChange(static function (\\Scope \$new, ?\\Scope \$old) use (\$saved) {
                if (null !== \$old) {
                    \$saved[\$old] = $save;
                }
                $restore
            });
            PHP];
    }

    /** @return array{label: string, code: string} */
    private static function annotate(string $code): array
    {
        return ['label' => 'Keep it: it holds nothing request-specific or private; annotate it so the doctor stops reporting it', 'code' => $code];
    }
}
