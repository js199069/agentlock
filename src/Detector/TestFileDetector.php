<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Detector;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Finding;
use Js199069\Agentlock\Php\MethodFacts;
use Js199069\Agentlock\Php\PhpInspector;

/** تست‌های حذف‌شده، skip‌شده، ضعیف‌شده، یا با assert بی‌معنی. */
final class TestFileDetector
{
    public function __construct(
        private PhpInspector $php = new PhpInspector(),
    ) {}

    /** @return list<Finding> */
    public function detect(FileChange $change): array
    {
        $old = $change->old !== null ? $this->tests($this->php->methods($change->old) ?? []) : [];

        if ($change->isDeleted()) {
            return $old === [] ? [] : [new Finding('test-file-deleted', Finding::HIGH, $change->path, null, sprintf('Test file deleted (%d test(s))', count($old)))];
        }

        $all = $this->php->methods((string) $change->new);
        if ($all === null) {
            return [new Finding('unparseable', Finding::MEDIUM, $change->path, null, 'Changed test file could not be parsed')];
        }
        $new = $this->tests($all);
        $findings = [];

        foreach ($old as $key => $o) {
            if (! isset($new[$key])) {
                $renamed = null;
                foreach ($new as $newKey => $n) {
                    if (! isset($old[$newKey]) && $n->bodyHash === $o->bodyHash) {
                        $renamed = $newKey;
                        break;
                    }
                }
                $findings[] = $renamed !== null
                    ? new Finding('test-renamed', Finding::LOW, $change->path, $new[$renamed]->line, "{$key} was renamed to {$renamed}")
                    : new Finding('test-removed', Finding::HIGH, $change->path, $o->line, "Test {$key} was removed");

                continue;
            }

            $n = $new[$key];
            if ($n->skips > $o->skips) {
                $findings[] = new Finding('test-skipped', Finding::HIGH, $change->path, $n->line, "Test {$key} is now skipped or marked incomplete");
            }
            if ($n->assertions < $o->assertions) {
                $findings[] = $n->assertions === 0
                    ? new Finding('test-emptied', Finding::HIGH, $change->path, $n->line, "Test {$key} has no assertions left (was {$o->assertions})")
                    : new Finding('assertions-reduced', Finding::MEDIUM, $change->path, $n->line, "Assertions in {$key}: {$o->assertions} → {$n->assertions}");
            } elseif ($n->exactAssertions < $o->exactAssertions) {
                $findings[] = new Finding('assertions-weakened', Finding::MEDIUM, $change->path, $n->line,
                    "Exact assertions in {$key}: {$o->exactAssertions} → {$n->exactAssertions}, replaced by weaker checks");
            }
            if (count($n->tautologyLines) > count($o->tautologyLines)) {
                $findings[] = new Finding('tautology', Finding::HIGH, $change->path, max($n->tautologyLines), "An assertion that cannot fail was added to {$key}");
            }
            if ($o->expectedLiterals !== [] && $n->expectedLiterals !== [] && $this->sorted($o->expectedLiterals) !== $this->sorted($n->expectedLiterals)) {
                $findings[] = new Finding('expectation-changed', Finding::MEDIUM, $change->path, $n->line, sprintf(
                    'Expected values in %s changed: [%s] → [%s]. Was the code fixed, or the test bent to the output?',
                    $key, $this->short($o->expectedLiterals), $this->short($n->expectedLiterals),
                ));
            }
            if ($n->unguardedTryInTest > $o->unguardedTryInTest) {
                $findings[] = new Finding('try-catch-in-test', Finding::MEDIUM, $change->path, $n->line, "A try/catch without fail() or an assertion was added to {$key}");
            }
        }

        foreach ($new as $key => $n) {
            if (isset($old[$key])) {
                continue;
            }
            if ($n->tautologyLines !== []) {
                $findings[] = new Finding('tautology', Finding::HIGH, $change->path, $n->tautologyLines[0], "New test {$key} contains an assertion that cannot fail");
            } elseif ($n->assertions === 0 && $n->skips === 0) {
                $findings[] = new Finding('test-without-assertions', Finding::MEDIUM, $change->path, $n->line, "New test {$key} has no assertions");
            }
            if ($n->skips > 0) {
                $findings[] = new Finding('new-test-skipped', Finding::MEDIUM, $change->path, $n->line, "New test {$key} is skipped");
            }
        }

        return $findings;
    }

    /** @param array<string, MethodFacts> $facts */
    private function tests(array $facts): array
    {
        return array_filter($facts, fn (MethodFacts $f) => $f->isTest);
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function short(array $values): string
    {
        return mb_strimwidth(implode(', ', $values), 0, 80, '…');
    }
}
