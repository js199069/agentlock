<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Mutation;

use RuntimeException;
use Symfony\Component\Process\Process;

/** MSI یک فایل را با Infection خود پروژه اندازه می‌گیرد (همه‌ی mutantها، با --with-uncovered). */
final class MsiProbe
{
    public function __construct(
        private string $root,
        private string $php = PHP_BINARY,
    ) {}

    public function measure(string $file): float
    {
        $summary = rtrim($this->root, '/\\') . '/.agentlock/tmp/infection-summary.json';
        @mkdir(dirname($summary), 0777, true);
        @unlink($summary);

        $process = new Process(
            [$this->php, 'vendor/bin/infection', '--filter=' . $file, '--with-uncovered', '--threads=4', '--no-progress', '--no-interaction', '--logger-summary-json=' . $summary],
            $this->root,
            ['XDEBUG_MODE' => 'coverage'],
            timeout: 1800,
        );
        $process->run();

        if (! is_file($summary)) {
            throw new RuntimeException("Infection produced no summary for {$file}:\n" . mb_substr($process->getOutput() . $process->getErrorOutput(), -3000));
        }

        $data = json_decode((string) file_get_contents($summary), true) ?: [];
        $msi = $data['stats']['msi'] ?? $data['msi'] ?? null;
        if ($msi === null) {
            throw new RuntimeException("No MSI in the Infection summary for {$file}");
        }

        return (float) $msi;
    }
}
