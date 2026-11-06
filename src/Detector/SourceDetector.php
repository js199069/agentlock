<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Detector;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Finding;
use Js199069\Agentlock\Php\PhpInspector;

/** در کد اصلی: خطای بلعیده‌شده، اعتبارسنجی حذف‌شده، حالت ویژه برای یک ورودی ثابت، و suppression تازه. */
final class SourceDetector
{
    public function __construct(
        private PhpInspector $php = new PhpInspector(),
    ) {}

    /** @return list<Finding> */
    public function detect(FileChange $change): array
    {
        if ($change->new === null) {
            return [];
        }

        $new = $this->php->methods($change->new);
        if ($new === null) {
            return [new Finding('unparseable', Finding::MEDIUM, $change->path, null, 'Changed PHP file could not be parsed')];
        }
        $old = $change->old !== null ? ($this->php->methods($change->old) ?? []) : [];
        $findings = [];

        $before = $change->old !== null ? $this->php->suppressions($change->old) : 0;
        $after = $this->php->suppressions($change->new);
        if ($after > $before) {
            $findings[] = new Finding('suppression-added', Finding::MEDIUM, $change->path, null, "Static-analysis or coverage suppressions: {$before} → {$after}");
        }

        foreach ($new as $key => $n) {
            $o = $old[$key] ?? null;

            if ($n->swallowedCatches > ($o?->swallowedCatches ?? 0)) {
                $findings[] = new Finding('swallowed-exception', Finding::HIGH, $change->path, $n->line, "{$key}: a catch block that swallows the error was added");
            }
            if ($n->literalSpecialCases > ($o?->literalSpecialCases ?? 0)) {
                $findings[] = new Finding('literal-special-case', Finding::MEDIUM, $change->path, $n->line,
                    "{$key}: a branch returning a fixed value for a fixed input was added (a common way to make one test pass)");
            }
            if ($o !== null && $n->throws < $o->throws) {
                $findings[] = new Finding('validation-removed', Finding::MEDIUM, $change->path, $n->line, "{$key}: throw statements {$o->throws} → {$n->throws}");
            }
        }

        return $findings;
    }
}
