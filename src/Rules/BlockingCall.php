<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use PhpParser\Node;

/** Notes calls that block the whole worker unless ext-phasync is loaded. */
final class BlockingCall extends Rule
{
    public const ID       = 'blocking-call';
    public const QUESTION = 'Is ext-phasync loaded in production?';
    public const ADVICE   = 'Without ext-phasync these calls block the worker: no other request in it runs until they return. With ext-phasync loaded they wait as coroutines, and the worker serves other requests meanwhile.';

    private const FUNCTIONS = [
        'sleep', 'usleep', 'time_nanosleep', 'time_sleep_until', 'flock', 'stream_select',
        'curl_exec', 'curl_multi_exec', 'proc_open', 'exec', 'shell_exec', 'system', 'passthru',
    ];

    public function enterNode(Node $node): null
    {
        $name = $this->functionName($node);
        if (null !== $name) {
            if (\in_array($name, self::FUNCTIONS, true) || ('file_get_contents' === $name && $this->isUrl($node)) || \str_starts_with($name, 'mysqli_')) {
                $this->hit(self::ID, Severity::Info, $node, $name . '()');
            }
        } elseif ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && \in_array($node->class->toLowerString(), ['pdo', 'mysqli'], true)) {
            $this->hit(self::ID, Severity::Info, $node, 'new ' . $node->class->toString());
        }

        return null;
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        $message = "$what blocks the worker without ext-phasync" . ($count > 1 ? " ($count sites)" : '');

        return [$message, self::QUESTION, [['label' => 'Load ext-phasync', 'code' => 'vendor/bin/swerve --ext ...   # or: pie install phasync/ext-phasync']]];
    }

    private function isUrl(Node\Expr\FuncCall $call): bool
    {
        $arg = $call->args[0] ?? null;

        return $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_ && 1 === \preg_match('#^https?://#i', $arg->value->value);
    }
}
