<?php

namespace Phasync\Doctor;

/**
 * Findings accepted as they are, for code that cannot be annotated (vendor). Stored as JSON:
 * {"accepted": [{"rule": ..., "symbol": ..., "reason": ...}]}, matched by rule and symbol.
 */
final class Baseline
{
    public const FILE = 'phasync-doctor.json';

    /** Findings the baseline accepted, by the last filter(). */
    public int $matched = 0;
    /** Entries that matched no finding, by the last filter(). */
    public int $stale = 0;

    /** @param array<string, array{rule: string, symbol: string, reason: string}> $entries keyed by rule and symbol */
    private function __construct(private array $entries)
    {
    }

    public static function load(?string $file): self
    {
        if (null === $file || !\is_file($file)) {
            return new self([]);
        }
        $doc     = \json_decode((string) \file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($doc['accepted'] ?? [] as $e) {
            $entries[$e['rule'] . "\0" . $e['symbol']] = ['rule' => $e['rule'], 'symbol' => $e['symbol'], 'reason' => (string) ($e['reason'] ?? '')];
        }

        return new self($entries);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding> the findings the baseline does not accept
     */
    public function filter(array $findings): array
    {
        $seen = [];
        $kept = [];
        foreach ($findings as $f) {
            $key = $f->rule . "\0" . $f->symbol;
            if (isset($this->entries[$key])) {
                $seen[$key] = true;
                ++$this->matched;
            } else {
                $kept[] = $f;
            }
        }
        $this->stale = \count($this->entries) - \count($seen);

        return $kept;
    }

    /**
     * Writes a baseline accepting these findings, keeping the reasons of entries already there.
     *
     * @param list<Finding> $findings
     */
    public function write(string $file, array $findings): void
    {
        $accepted = [];
        foreach ($findings as $f) {
            $key            = $f->rule . "\0" . $f->symbol;
            $accepted[$key] = ['rule' => $f->rule, 'symbol' => $f->symbol, 'reason' => $this->entries[$key]['reason'] ?? ''];
        }
        \ksort($accepted);
        \file_put_contents($file, \json_encode(['doctor_version' => Cli::VERSION, 'accepted' => \array_values($accepted)], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
    }
}
