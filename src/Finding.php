<?php

namespace Phasync\Doctor;

/**
 * One candidate: a piece of state or a call, where it is, every site that writes or reads it, the
 * question to answer about it and the ways to fix it.
 *
 * `symbol` is a stable id (no line numbers): with `rule` it is the key a baseline accepts a finding by.
 */
final class Finding
{
    /**
     * @param list<array{file: string, line: int, code: string}> $writes  sites that change the state or make the call
     * @param list<array{file: string, line: int, code: string}> $reads   sites that only read it
     * @param list<array{label: string, code: string}>           $options ways to resolve it, with ready code
     */
    public function __construct(
        public readonly string $rule,
        public readonly Severity $severity,
        public readonly string $file,
        public readonly int $line,
        public readonly string $symbol,
        public readonly string $message,
        public readonly array $writes = [],
        public readonly array $reads = [],
        public readonly string $question = '',
        public readonly array $options = [],
        public readonly ?string $docs = null,
    ) {
    }

    /** @return array<string, mixed> the finding as documented in the JSON schema */
    public function toArray(): array
    {
        return [
            'rule'     => $this->rule,
            'severity' => $this->severity->value,
            'file'     => $this->file,
            'line'     => $this->line,
            'symbol'   => $this->symbol,
            'message'  => $this->message,
            'writes'   => $this->writes,
            'reads'    => $this->reads,
            'question' => $this->question,
            'options'  => $this->options,
            'docs'     => $this->docs,
        ];
    }
}
