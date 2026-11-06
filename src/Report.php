<?php

declare(strict_types=1);

namespace Js199069\Agentlock;

final class Report
{
    /** @param list<Finding> $findings */
    public static function failed(array $findings, string $failOn): bool
    {
        if ($failOn === 'none') {
            return false;
        }
        $min = Finding::rank($failOn);
        foreach ($findings as $finding) {
            if (Finding::rank($finding->severity) >= $min) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Finding> $findings */
    public static function text(array $findings, int $changedFiles): string
    {
        if ($findings === []) {
            return "agentlock: {$changedFiles} changed file(s), nothing suspicious.\n";
        }

        usort($findings, fn (Finding $a, Finding $b) => Finding::rank($b->severity) <=> Finding::rank($a->severity));
        $out = '';
        foreach ($findings as $f) {
            $out .= sprintf("[%s] %s  %s%s\n    %s\n", strtoupper($f->severity), $f->rule, $f->file, $f->line ? ':' . $f->line : '', $f->message);
        }
        $counts = array_count_values(array_map(fn (Finding $f) => $f->severity, $findings));

        return $out . sprintf("\nagentlock: %d high, %d medium, %d low in %d changed file(s).\n",
            $counts[Finding::HIGH] ?? 0, $counts[Finding::MEDIUM] ?? 0, $counts[Finding::LOW] ?? 0, $changedFiles);
    }

    /** @param list<Finding> $findings */
    public static function json(array $findings): string
    {
        return json_encode(array_map(fn (Finding $f) => $f->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
