<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use Phasync\Doctor\Writes;
use PhpParser\Node;

/** Reports $_SESSION and session_*() use, per function, with what the detected adapter does about it. */
final class Session extends Rule
{
    public const ID       = 'session';
    public const QUESTION = 'Whose session does this read or write when requests share the worker?';
    public const ADVICE   = 'PHP\'s session module keeps one session id and one $_SESSION per process, and Scope leaves them alone. Where requests overlap in a worker they read and write each other\'s session, another visitor\'s included.';

    private const GETTERS = ['session_status', 'session_get_cookie_params', 'session_save_path', 'session_module_name', 'session_id', 'session_name', 'session_cache_limiter', 'session_cache_expire'];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\Variable && '_SESSION' === $node->name) {
            $this->hit(self::ID, $this->severity(), $node, 'session', Writes::isWrite($node));
        } elseif (null !== ($name = $this->functionName($node)) && \str_starts_with($name, 'session_') && 'session_create_id' !== $name) {
            $this->hit(self::ID, $this->severity(), $node, 'session', !(\in_array($name, self::GETTERS, true) && [] === $node->args));
        }

        return null;
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        $adapter = $this->project->adapter['package'] ?? null;
        if ($this->project->oneAtATime()) {
            return ["$adapter runs requests one at a time and gives each its own session id: safe inside the request, not in code that runs after the response (a WebSocket callback, a stream body)", self::QUESTION,
                [['label' => 'Read the session in the request, before returning the response, and pass the values on', 'code' => <<<'PHP'
                    $userId = $_SESSION['user_id'] ?? null;   // now, in the request
                    return WebSocketResponse::from($request, function (WebSocket $ws) use ($userId) {
                        // use $userId here, not $_SESSION
                    });
                    PHP]]];
        }

        return match ($adapter) {
            'phasync/swerve-laravel' => ['$_SESSION and session_*() are PHP\'s one session per process; Laravel\'s session is per application', self::QUESTION,
                [['label' => 'Use Laravel\'s session', 'code' => "\$request->session()->put('key', \$value);\n\$value = \$request->session()->get('key');"]]],
            'phasync/swerve-symfony' => ['$_SESSION and session_*() are PHP\'s one session per process; Swerve\Symfony\SessionStorageFactory gives each request a storage of its own', self::QUESTION,
                [['label' => 'Use the request\'s session with swerve-symfony\'s storage', 'code' => "# config/packages/framework.yaml\nframework:\n    session:\n        storage_factory_id: Swerve\\Symfony\\SessionStorageFactory\n\n// in code:\n\$request->getSession()->set('key', \$value);"]]],
            default => ['$_SESSION and session_*() are one session per worker process, shared by overlapping requests', self::QUESTION,
                [['label' => 'Keep session data per request', 'code' => '// load the session by the request\'s cookie into a per-request object (a PSR-15 session middleware with its own storage), and save it before the response; do not use $_SESSION']]],
        };
    }

    private function severity(): Severity
    {
        return $this->project->oneAtATime() ? Severity::Info : Severity::Warning;
    }
}
