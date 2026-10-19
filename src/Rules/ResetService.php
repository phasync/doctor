<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Finding;
use Phasync\Doctor\Project;
use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use PhpParser\Node;

/**
 * Notes services the framework resets between requests, because they hold request state: classes
 * implementing Symfony's ResetInterface, and Yii container definitions with a `reset` callback.
 */
final class ResetService extends Rule
{
    public const ID       = 'reset-service';
    public const QUESTION = 'Does code that runs after the response (a WebSocket callback, a stream body, a coroutine) read this service?';
    public const ADVICE   = 'The framework resets these services between requests, so they hold request state. Inside the request that is the request\'s own state; after the response it belongs to whichever request the worker serves next.';

    /** @var array<string, array{file: string, line: int, code: string}> */
    private array $services = [];

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_ && null !== $node->namespacedName) {
            foreach ($node->implements as $interface) {
                if ('Symfony\Contracts\Service\ResetInterface' === $interface->toString()) {
                    $this->services[$node->namespacedName->toString()] ??= $this->site($node);
                }
            }
        } elseif ($node instanceof Node\ArrayItem && $node->key instanceof Node\Expr\ClassConstFetch && $node->key->class instanceof Node\Name
            && $node->value instanceof Node\Expr\Array_) {
            foreach ($node->value->items as $item) {
                if ($item?->key instanceof Node\Scalar\String_ && 'reset' === $item->key->value && $item->value instanceof Node\Expr\Closure) {
                    $this->services[$node->key->class->toString()] ??= $this->site($node);
                }
            }
        }

        return null;
    }

    public function finish(): void
    {
        parent::finish();
        $how = match ($this->project->adapter['concurrency'] ?? null) {
            'one-at-a-time' => $this->project->adapter['package'] . ' runs requests one at a time, so it is the request\'s own while the request runs',
            'concurrent'    => \in_array($this->project->adapter['package'], ['phasync/swerve-laravel', 'phasync/swerve-symfony'], true)
                ? 'each request has an application or kernel of its own, so overlapping requests do not share it'
                : 'requests overlap in the worker: a shared instance is seen by all of them',
            default         => 'where requests overlap in the worker, a shared instance is seen by all of them',
        };
        foreach ($this->services as $class => $site) {
            $this->findings[] = new Finding(self::ID, Severity::Info, $site['file'], $site['line'], $class,
                "$class is reset between requests, so it holds request state; $how", [$site], [], self::QUESTION, [
                    ['label' => 'Take what code after the response needs while the request runs, and pass it in', 'code' => <<<'PHP'
                        $userId = $currentUser->getId();   // now, in the request
                        return WebSocketResponse::from($request, function (WebSocket $ws) use ($userId) {
                            // use $userId here, not the service
                        });
                        PHP],
                ], Project::GUIDE);
        }
    }
}
