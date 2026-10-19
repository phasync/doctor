<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Reports registration of process-wide handlers. */
final class GlobalHandler extends Rule
{
    public const ID       = 'global-handler';
    public const QUESTION = 'Does this run for every request? Then register it once at boot.';
    public const ADVICE   = 'Error, exception, shutdown, tick and autoload handlers belong to the worker process, not the request: one registered during a request replaces the previous one or piles up with every request, and handles other requests\' errors too. register_shutdown_function() runs when the worker exits, not when a request ends. Info at file level, or when the same function restores the handler (it matters only if the code waits for I/O in between); a warning inside a function or method.';

    private const FUNCTIONS = ['set_error_handler', 'set_exception_handler', 'register_shutdown_function', 'register_tick_function', 'spl_autoload_register'];
    /** Handlers a function may install for a moment and restore. */
    private const RESTORE = ['set_error_handler' => 'restore_error_handler', 'set_exception_handler' => 'restore_exception_handler'];

    public function enterNode(Node $node): null
    {
        $name = $this->functionName($node);
        if (null !== $name && \in_array($name, self::FUNCTIONS, true)) {
            $temporary = isset(self::RESTORE[$name]) && null !== ($f = $this->ownFunction($node))
                && null !== (new NodeFinder())->findFirst($f, fn (Node $n) => $this->functionName($n) === self::RESTORE[$name]);
            $this->hit(self::ID, $this->insideFunction($node) && !$temporary ? Severity::Warning : Severity::Info, $node, $name . '()');
        }

        return null;
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        $options = [['label' => 'Register it once at boot', 'code' => "// in the bootstrap, once per worker\n\\set_error_handler(\$handler);"]];
        if ('register_shutdown_function()' === $what) {
            $options[] = ['label' => 'Run per-request cleanup when the request ends, not at shutdown', 'code' => "try {\n    return \$handler->handle(\$request);\n} finally {\n    \$cleanup();\n}"];
        }

        return ["$what registers a handler for the whole worker" . ($count > 1 ? " ($count sites)" : ''), self::QUESTION, $options];
    }
}
