<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Detector;

use DOMDocument;
use DOMXPath;
use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Finding;

/** تنظیماتی که دروازه‌های کیفیت را سهل‌گیرتر می‌کنند: PHPUnit، PHPStan، Psalm، Composer، Infection، CI. */
final class ConfigDetector
{
    private const CHECKS = '/\b(phpunit|pest|paratest|infection|phpstan|psalm)\b/';

    public function supports(string $path): bool
    {
        $base = strtolower(basename($path));

        return (bool) preg_match('/^(phpunit\.xml(\.dist)?|phpstan\.neon(\.dist)?|psalm\.xml(\.dist)?|composer\.json|infection\.json5?(\.dist)?|\.gitlab-ci\.yml)$/', $base)
            || (str_ends_with($base, '.neon') && str_contains($base, 'baseline'))
            || (bool) preg_match('#(^|/)\.github/workflows/[^/]+\.ya?ml$#i', $path);
    }

    /** @return list<Finding> */
    public function detect(FileChange $change): array
    {
        if ($change->new === null) {
            return [new Finding('config-deleted', Finding::MEDIUM, $change->path, null, 'A quality-gate configuration file was deleted')];
        }

        $base = strtolower(basename($change->path));
        $old = $change->old ?? '';
        $new = $change->new;

        return match (true) {
            str_starts_with($base, 'phpunit.xml') => $this->phpunit($change->path, $change->old, $new),
            str_contains($base, 'baseline') && str_ends_with($base, '.neon') => $this->grew($change->path, '/message:/', $old, $new, 'baseline-grew', 'PHPStan baseline entries'),
            str_starts_with($base, 'phpstan.neon') => $this->phpstan($change->path, $old, $new),
            str_starts_with($base, 'psalm.xml') => $this->psalm($change->path, $old, $new),
            $base === 'composer.json' => $this->composer($change->path, $old, $new),
            str_starts_with($base, 'infection.json') => $this->infection($change->path, $old, $new),
            default => $this->ci($change->path, $old, $new),
        };
    }

    private function phpunit(string $path, ?string $old, string $new): array
    {
        $o = $this->xml($old);
        $n = $this->xml($new);
        if ($o === null || $n === null) {
            return [];
        }

        $findings = [];
        $suite = '//testsuite/directory | //testsuite/file';
        $removed = array_diff($this->query($o, $suite), $this->query($n, $suite));
        if ($removed !== []) {
            $findings[] = new Finding('suite-shrunk', Finding::HIGH, $path, null, 'Removed from the test suite: ' . implode(', ', $removed));
        }
        $excluded = array_diff($this->query($n, '//testsuite/exclude'), $this->query($o, '//testsuite/exclude'));
        if ($excluded !== []) {
            $findings[] = new Finding('suite-excluded', Finding::HIGH, $path, null, 'Excluded from the test suite: ' . implode(', ', $excluded));
        }

        foreach ($o->documentElement?->attributes ?? [] as $attr) {
            if ($attr->value !== 'true' || ! preg_match('/^(failOn|beStrictAbout|requireCoverageMetadata|enforceTimeLimit)/', $attr->name)) {
                continue;
            }
            $now = (string) $n->documentElement?->getAttribute($attr->name);
            if ($now !== 'true') {
                $findings[] = new Finding('strictness-lowered', Finding::MEDIUM, $path, null, sprintf('%s: true → %s', $attr->name, $now === '' ? 'removed' : $now));
            }
        }

        return $findings;
    }

    private function phpstan(string $path, string $old, string $new): array
    {
        $findings = [];
        $before = $this->level($old);
        $after = $this->level($new);
        if ($before !== null && $after !== null && $after < $before) {
            $findings[] = new Finding('analysis-level-lowered', Finding::HIGH, $path, null, sprintf('PHPStan level %s → %s', $before === 100 ? 'max' : $before, $after === 100 ? 'max' : $after));
        }
        array_push($findings, ...$this->grew($path, '/(message:|identifier:|-\s*[\'"]#)/', $old, $new, 'ignore-errors-added', 'PHPStan ignored errors'));
        if (str_contains($new, 'baseline') && ! str_contains($old, 'baseline')) {
            $findings[] = new Finding('baseline-added', Finding::MEDIUM, $path, null, 'A PHPStan baseline was included: existing errors are now ignored');
        }

        return $findings;
    }

    private function psalm(string $path, string $old, string $new): array
    {
        $level = fn (string $s) => preg_match('/errorLevel="(\d)"/', $s, $m) ? (int) $m[1] : null;
        $before = $level($old);
        $after = $level($new);

        return $before !== null && $after !== null && $after > $before
            ? [new Finding('analysis-level-lowered', Finding::HIGH, $path, null, "Psalm errorLevel {$before} → {$after} (higher is more lenient)")]
            : [];
    }

    private function composer(string $path, string $old, string $new): array
    {
        $scripts = fn (string $json) => $this->strings((json_decode($json, true) ?: [])['scripts'] ?? []);
        $o = $scripts($old);
        $n = $scripts($new);
        $findings = [];

        $ignore = '/\|\|\s*(true|exit\s+0|:)(\s|;|$)/';
        if ($this->countIn($n, $ignore) > $this->countIn($o, $ignore)) {
            $findings[] = new Finding('script-ignores-failure', Finding::HIGH, $path, null, 'A Composer script now ignores its exit code (|| true)');
        }
        if ($this->countIn($n, self::CHECKS) < $this->countIn($o, self::CHECKS)) {
            $findings[] = new Finding('checks-removed', Finding::MEDIUM, $path, null, 'Composer scripts run fewer test or analysis tools than before');
        }

        return $findings;
    }

    private function infection(string $path, string $old, string $new): array
    {
        $gates = function (string $s): array {
            preg_match_all('/"?(minMsi|minCoveredMsi)"?\s*:\s*([\d.]+)/', $s, $m, PREG_SET_ORDER);

            return array_column($m, 2, 1);
        };
        $findings = [];
        $after = $gates($new);
        foreach ($gates($old) as $name => $value) {
            $now = $after[$name] ?? null;
            if ($now === null || (float) $now < (float) $value) {
                $findings[] = new Finding('quality-gate-lowered', Finding::HIGH, $path, null, sprintf('%s: %s → %s', $name, $value, $now ?? 'removed'));
            }
        }

        return $findings;
    }

    private function ci(string $path, string $old, string $new): array
    {
        $findings = [];
        $count = fn (string $pattern, string $s) => (int) preg_match_all($pattern, $s);

        if ($count('/continue-on-error:\s*true/', $new) > $count('/continue-on-error:\s*true/', $old)
            || $count('/\|\|\s*(true|exit\s+0)/', $new) > $count('/\|\|\s*(true|exit\s+0)/', $old)) {
            $findings[] = new Finding('ci-failure-ignored', Finding::HIGH, $path, null, 'A CI step now ignores failure');
        }
        if ($count(self::CHECKS, $new) < $count(self::CHECKS, $old)) {
            $findings[] = new Finding('ci-checks-reduced', Finding::MEDIUM, $path, null, 'CI runs fewer test or analysis tools than before');
        }

        return $findings;
    }

    private function grew(string $path, string $pattern, string $old, string $new, string $rule, string $label): array
    {
        $before = (int) preg_match_all($pattern, $old);
        $after = (int) preg_match_all($pattern, $new);

        return $after > $before ? [new Finding($rule, Finding::MEDIUM, $path, null, "{$label}: {$before} → {$after}")] : [];
    }

    private function level(string $neon): ?int
    {
        return preg_match('/^\s*level:\s*(\w+)/m', $neon, $m) ? ($m[1] === 'max' ? 100 : (int) $m[1]) : null;
    }

    private function xml(?string $source): ?DOMDocument
    {
        if ($source === null || trim($source) === '') {
            return null;
        }
        $doc = new DOMDocument();

        return @$doc->loadXML($source) ? $doc : null;
    }

    /** @return list<string> */
    private function query(DOMDocument $doc, string $xpath): array
    {
        $values = [];
        foreach ((new DOMXPath($doc))->query($xpath) ?: [] as $node) {
            $values[] = trim($node->textContent);
        }

        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_merge([], ...array_map($this->strings(...), array_values($value))) : [];
    }

    private function countIn(array $strings, string $pattern): int
    {
        return array_sum(array_map(fn ($s) => (int) preg_match_all($pattern, $s), $strings));
    }
}
