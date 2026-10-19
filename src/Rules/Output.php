<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use PhpParser\Node;

/** Reports PHP's process-wide output buffers and the SAPI response functions. */
final class Output extends Rule
{
    public const ID       = 'output-buffering';
    public const RESPONSE = 'response-output';

    private const RESPONSE_FUNCTIONS = ['header', 'header_remove', 'setcookie', 'setrawcookie', 'http_response_code'];

    public function advice(): array
    {
        return [
            self::ID       => 'PHP\'s output buffer stack is one per process. Where requests overlap, a request that waits while a buffer is open (I/O with ext-phasync, phasync::sleep()) lets another request\'s output land in its buffer. Under an adapter that runs requests one at a time this is safe.',
            self::RESPONSE => 'In a swerve worker, header(), setcookie() and http_response_code() do not make the response: the adapter sends the framework\'s response object. Set headers, cookies and the status on the response.',
        ];
    }

    public function enterNode(Node $node): null
    {
        $name = $this->functionName($node);
        if (null === $name) {
            return null;
        }
        if (\str_starts_with($name, 'ob_') || \in_array($name, ['flush', 'output_add_rewrite_var', 'output_reset_rewrite_vars'], true)) {
            $this->hit(self::ID, $this->project->oneAtATime() ? Severity::Info : Severity::Warning, $node, $name . '()');
        } elseif (\in_array($name, self::RESPONSE_FUNCTIONS, true) && ('http_response_code' !== $name || [] !== $node->args)) {
            $this->hit(self::RESPONSE, Severity::Warning, $node, $name . '()');
        }

        return null;
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        $sites = $count > 1 ? " ($count sites)" : '';
        if (self::RESPONSE === $rule) {
            return ["$what does not reach the response in a swerve worker$sites", 'Is this part of the response? Then set it on the framework\'s response object.', [
                ['label' => 'Set it on the response object', 'code' => "// PSR-7\n\$response = \$response->withHeader('X-Name', 'value')->withStatus(404);\n// Symfony / Laravel\n\$response->headers->set('X-Name', 'value');\n\$response->headers->setCookie(new \\Symfony\\Component\\HttpFoundation\\Cookie('name', 'value'));"],
            ]];
        }

        return ["$what uses the process-wide output buffer$sites", 'Can the request wait (a query, an HTTP call, a sleep) while this buffer is open?', [
            ['label' => 'Build the output as a string, or return it as the response body', 'code' => "// instead of ob_start(); include \$template; \$html = ob_get_clean();\n\$html = \$renderer->render(\$template, \$data); // a renderer that returns a string"],
            ['label' => 'Load what the template needs before buffering starts, so nothing waits while the buffer is open', 'code' => "\$data = \$repository->load(); // all I/O first\nob_start();\ninclude \$template;\n\$html = ob_get_clean();"],
        ]];
    }
}
