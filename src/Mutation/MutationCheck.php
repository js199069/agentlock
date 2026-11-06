<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Mutation;

use Js199069\Agentlock\Finding;
use Js199069\Agentlock\Scanner;

/**
 * تست‌ها هنوز سبزند، ولی ضعیف‌تر شده‌اند؟ MSI فعلی هر فایل با baseline (ثبت‌شده روی یک commit مطمئن) مقایسه می‌شود.
 * الگوهای ظاهری این را نمی‌بینند؛ mutation می‌بیند.
 */
final class MutationCheck
{
    private MsiProbe $probe;

    public function __construct(
        private string $root,
        ?MsiProbe $probe = null,
        private float $tolerance = 0.5,
    ) {
        $this->probe = $probe ?? new MsiProbe($root);
    }

    /** @return array<string, float> */
    public function baseline(array $files, ?callable $progress = null): array
    {
        $data = $this->load();
        foreach ($files as $file) {
            $file = str_replace('\\', '/', $file);
            $data[$file] = $this->probe->measure($file);
            $progress && $progress($file, $data[$file]);
        }
        ksort($data);
        @mkdir(dirname($this->path()), 0777, true);
        file_put_contents($this->path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $data;
    }

    /** @return list<Finding> */
    public function check(?callable $progress = null): array
    {
        $data = $this->load();
        if ($data === []) {
            return [new Finding('no-msi-baseline', Finding::LOW, Scanner::BASELINE, null, "No MSI baseline; run 'agentlock baseline <files>' on a trusted commit")];
        }

        $findings = [];
        foreach ($data as $file => $msi) {
            $now = $this->probe->measure($file);
            $progress && $progress($file, (float) $msi, $now);
            if ($now < (float) $msi - $this->tolerance) {
                $findings[] = new Finding('msi-dropped', Finding::HIGH, $file, null, sprintf('MSI %.2f%% → %.2f%%: the tests now catch fewer injected bugs in this file', $msi, $now));
            }
        }

        return $findings;
    }

    private function load(): array
    {
        return is_file($this->path()) ? (json_decode((string) file_get_contents($this->path()), true) ?: []) : [];
    }

    private function path(): string
    {
        return rtrim($this->root, '/\\') . '/' . Scanner::BASELINE;
    }
}
