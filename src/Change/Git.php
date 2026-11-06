<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Change;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * تغییرات را از گیت می‌خواند. سه حالت:
 * working: درخت کاری در برابر HEAD (به‌علاوه‌ی فایل‌های تازه‌ی track‌نشده)
 * staged:  index در برابر HEAD (برای hook پیش از commit)
 * base:    HEAD در برابر merge-base با یک شاخه (برای CI و PR)
 */
final class Git
{
    public function __construct(
        private string $dir,
    ) {}

    /** @return list<FileChange> */
    public function changes(string $mode = 'working', ?string $base = null): array
    {
        if ($mode === 'base') {
            $from = trim($this->run(['merge-base', $base ?? throw new InvalidArgumentException('--base requires a ref'), 'HEAD']));
            $args = ['diff', '--name-status', '-M', $from, 'HEAD'];
            $oldRev = $from;
            $newRev = 'HEAD';
        } elseif ($mode === 'staged') {
            $args = ['diff', '--cached', '--name-status', '-M'];
            $oldRev = 'HEAD';
            $newRev = ':';
        } else {
            $args = ['diff', '--name-status', '-M', 'HEAD'];
            $oldRev = 'HEAD';
            $newRev = null;
        }

        $changes = [];
        foreach ($this->lines($this->run($args)) as $line) {
            $parts = explode("\t", $line);
            $code = $parts[0][0] ?? '';
            if ($code === 'R' || $code === 'C') {
                [$oldPath, $path, $status] = [$parts[1] ?? '', $parts[2] ?? '', 'R'];
            } else {
                [$oldPath, $path, $status] = [$parts[1] ?? '', $parts[1] ?? '', $code];
            }
            if ($path === '' || $this->ignored($path)) {
                continue;
            }

            $changes[] = new FileChange(
                $path,
                $status,
                $status === 'A' ? null : $this->show($oldRev, $oldPath),
                $status === 'D' ? null : $this->content($newRev, $path),
                $oldPath,
            );
        }

        if ($mode === 'working') {
            foreach ($this->lines($this->run(['ls-files', '--others', '--exclude-standard'])) as $path) {
                if (! $this->ignored($path)) {
                    $changes[] = new FileChange($path, 'A', null, $this->content(null, $path));
                }
            }
        }

        return $changes;
    }

    private function content(?string $rev, string $path): ?string
    {
        if ($rev === null) {
            $file = rtrim($this->dir, '/\\') . '/' . $path;

            return is_file($file) ? (string) file_get_contents($file) : null;
        }

        return $this->show($rev === ':' ? '' : $rev, $path);
    }

    private function show(string $rev, string $path): ?string
    {
        $process = new Process(['git', '-c', 'core.quotepath=false', 'show', $rev . ':' . $path], $this->dir);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    private function run(array $args): string
    {
        $process = new Process(['git', '-c', 'core.quotepath=false', ...$args], $this->dir);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git ' . implode(' ', $args) . ' failed: ' . trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $output))), fn ($l) => $l !== ''));
    }

    private function ignored(string $path): bool
    {
        return str_starts_with($path, 'vendor/') || str_starts_with($path, 'node_modules/') || str_starts_with($path, '.agentlock/tmp');
    }
}
