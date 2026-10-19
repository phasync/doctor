<?php

namespace Phasync\Doctor\Rules;

use Phasync\Doctor\Fixes;
use Phasync\Doctor\Rule;
use Phasync\Doctor\Severity;
use Phasync\Doctor\Writes;
use PhpParser\Node;

/**
 * Reports changes to settings of the whole process: setlocale(), runtime ini, the working directory,
 * the environment, $_SERVER and $_ENV. Notes the settings Scope keeps per request.
 */
final class ProcessSetting extends Rule
{
    public const ID        = 'process-setting';
    public const SETLOCALE = 'setlocale';
    public const MANAGED   = 'scope-managed';
    public const QUESTION  = 'Is this set to the same value for every request? Then set it once at boot. If it changes per request, every request in the worker sees the last value: pass the value explicitly instead.';

    /** Setters whatever their arguments. */
    private const ALWAYS = ['ini_set', 'ini_alter', 'ini_restore', 'set_include_path', 'chdir', 'putenv', 'iconv_set_encoding'];
    /** Setters when called with arguments, getters without. */
    private const WITH_ARG = [
        'mb_internal_encoding', 'mb_regex_encoding', 'mb_substitute_character', 'mb_language', 'mb_http_output', 'mb_detect_order',
        'error_reporting', 'umask', 'libxml_use_internal_errors', 'bcscale', 'mt_srand', 'srand',
    ];
    /** Settings Scope keeps per request (secondary vote B of the Scope RFC). */
    private const MANAGED_FUNCTIONS = ['date_default_timezone_set', 'locale_set_default'];

    public function advice(): array
    {
        return [
            self::SETLOCALE => 'setlocale() changes the C locale of the whole process: number and date formatting, character classes and string comparison change for every request in the worker, also mid-way through another request. Scope does not keep it per request. The intl default locale (Locale::setDefault()) is kept per request by Scope.',
            self::ID        => 'These calls change a setting of the whole worker process (or write $_SERVER/$_ENV, which Scope does not keep per request), so every request in the worker sees the last value. Info when the same function sets it back: a temporary change, which matters only if the code waits for I/O in between.',
            self::MANAGED   => 'Scope keeps these per request: the default timezone and the intl default locale (secondary vote B of the Scope RFC) and $_REQUEST (with ext-scope, bundled in ext-phasync). Nothing to do; listed so you know they were seen.',
        ];
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\Variable && \in_array($node->name, ['_SERVER', '_ENV', '_REQUEST'], true)) {
            if (Writes::isWrite($node)) {
                '_REQUEST' === $node->name
                    ? $this->hit(self::MANAGED, Severity::Info, $node, '$_REQUEST')
                    : $this->hit(self::ID, Severity::Warning, $node, '$' . $node->name);
            }

            return null;
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && 'locale' === $node->class->toLowerString()
            && $node->name instanceof Node\Identifier && 'setdefault' === $node->name->toLowerString()) {
            $this->hit(self::MANAGED, Severity::Info, $node, 'Locale::setDefault()');

            return null;
        }
        $name = $this->functionName($node);
        if (null === $name) {
            return null;
        }
        $hasArgs = [] !== $node->args;
        match (true) {
            'setlocale' === $name                              => $this->hit(self::SETLOCALE, $this->isGetter($node) ? Severity::Info : Severity::Warning, $node, 'setlocale()', !$this->isGetter($node)),
            \in_array($name, self::MANAGED_FUNCTIONS, true)    => $this->hit(self::MANAGED, Severity::Info, $node, $name . '()'),
            \in_array($name, self::ALWAYS, true)               => $this->hit(self::ID, Severity::Warning, $node, $name . '()'),
            \in_array($name, self::WITH_ARG, true) && $hasArgs => $this->hit(self::ID, $this->setterCalls($node, $name) > 1 ? Severity::Info : Severity::Warning, $node, $name . '()'),
            'set_time_limit' === $name,
            'ignore_user_abort' === $name && $hasArgs        => $this->hit(self::ID, Severity::Info, $node, $name . '()'),
            default                                          => null,
        };

        return null;
    }

    protected function describe(string $rule, string $what, int $count): array
    {
        $sites = $count > 1 ? " ($count sites)" : '';

        return match ($rule) {
            self::SETLOCALE => ["setlocale() changes the locale of the whole worker$sites", 'Can this use Locale::setDefault()/Locale::getDefault() and intl formatters, which Scope keeps per request, instead of setlocale()?', [
                ['label' => 'Refactor to the intl default locale, which Scope keeps per request', 'code' => <<<'PHP'
                    // instead of setlocale(LC_ALL, 'nb_NO.UTF-8') and locale-dependent functions:
                    \Locale::setDefault('nb_NO');
                    $number = (new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL))->format($value);
                    $date   = (new \IntlDateFormatter(\Locale::getDefault(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE))->format($time);
                    PHP],
                Fixes::swapSetting('Fallback, where refactoring is impossible: save and restore it per request in phasync::onScopeChange()', '\setlocale(\LC_ALL, 0)', '\setlocale(\LC_ALL, $saved[$new] ?? \'C\');'),
            ]],
            self::MANAGED => ["$what is kept per request by Scope$sites", '', []],
            default       => [$this->message($what) . $sites, self::QUESTION, [
                ['label' => 'Set it once at boot when every request uses the same value', 'code' => "// in the application's bootstrap or php.ini, not in request code\n" . $this->example($what)],
                ['label' => 'Pass a per-request value explicitly instead of changing the process', 'code' => $this->explicit($what)],
            ]],
        };
    }

    private function message(string $what): string
    {
        return match ($what) {
            '$_SERVER', '$_ENV'                 => "writes to $what change it for the whole worker (Scope keeps \$_GET, \$_POST, \$_COOKIE and \$_FILES per request, not $what)",
            'set_time_limit()'                  => 'set_time_limit() applies to the worker process, not to one request',
            'ignore_user_abort()'               => 'ignore_user_abort() applies to the worker process, not to one request',
            default                             => "$what changes a setting of the whole worker",
        };
    }

    private function example(string $what): string
    {
        return match ($what) {
            '$_SERVER', '$_ENV' => "{$what}['NAME'] = 'value';",
            'ini_set()'         => "\\ini_set('memory_limit', '256M');",
            'chdir()'           => '\chdir(__DIR__);',
            default             => \substr($what, 0, -1) . '...);',
        };
    }

    private function explicit(string $what): string
    {
        return match ($what) {
            '$_SERVER', '$_ENV' => '// read request values from the request object, e.g. $request->getServerParams()[\'NAME\'] in PSR-7, and pass them on',
            'chdir()'           => '// use absolute paths instead of changing directory: file_get_contents($dir . \'/file.txt\')',
            'putenv()'          => '// read configuration into a value at boot; pass per-request values as arguments, not through the environment',
            default             => '// pass the value to the code that needs it, or use an API that takes it as an argument',
        };
    }

    private function isGetter(Node\Expr\FuncCall $call): bool
    {
        $locale = $call->args[1] ?? null;

        return $locale instanceof Node\Arg && (
            ($locale->value instanceof Node\Scalar\Int_ && 0 === $locale->value->value)
            || ($locale->value instanceof Node\Scalar\String_ && '0' === $locale->value->value)
        );
    }
}
