<?php

declare(strict_types=1);

namespace Js199069\Agentlock;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Detector\ConfigDetector;
use Js199069\Agentlock\Detector\SourceDetector;
use Js199069\Agentlock\Detector\TestFileDetector;

/** هر فایل تغییرکرده را به بازرس مناسبش می‌سپارد. */
final class Scanner
{
    public const LOCK = '.agentlock/lock.json';
    public const BASELINE = '.agentlock/msi.json';

    public function __construct(
        private TestFileDetector $tests = new TestFileDetector(),
        private SourceDetector $source = new SourceDetector(),
        private ConfigDetector $config = new ConfigDetector(),
    ) {}

    /**
     * @param list<FileChange> $changes
     * @return list<Finding>
     */
    public function scan(array $changes): array
    {
        $findings = [];

        foreach ($changes as $change) {
            $path = str_replace('\\', '/', $change->path);

            if ($path === self::LOCK || $path === self::BASELINE) {
                $findings[] = new Finding('lock-modified', Finding::HIGH, $path, null, 'Locks and baselines must be changed by a human, not by an agent');

                continue;
            }
            if ($this->config->supports($path)) {
                array_push($findings, ...$this->config->detect($change));

                continue;
            }
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            array_push($findings, ...(self::isTest($path) ? $this->tests->detect($change) : $this->source->detect($change)));
        }

        return $findings;
    }

    public static function isTest(string $path): bool
    {
        return (bool) preg_match('#(^|/)tests?/#i', $path) || str_ends_with($path, 'Test.php');
    }
}
