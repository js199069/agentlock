<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Lock;

use FilesystemIterator;
use Js199069\Agentlock\Finding;
use Js199069\Agentlock\Php\PhpInspector;
use Js199069\Agentlock\Scanner;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * قرارداد: فایل‌ها یا تست‌هایی که agent نباید تغییر بدهد.
 * فایل با hash کل محتوا قفل می‌شود؛ یک تست با hash بدنه‌اش (پس تغییر تست‌های دیگر همان فایل آزاد است).
 */
final class Locker
{
    public function __construct(
        private string $root,
        private PhpInspector $php = new PhpInspector(),
    ) {}

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** @return array{version: int, files: array<string, string>, tests: array<string, string>} */
    public function load(): array
    {
        $data = $this->exists() ? (json_decode((string) file_get_contents($this->path()), true) ?: []) : [];

        return ['version' => 1, 'files' => $data['files'] ?? [], 'tests' => $data['tests'] ?? []];
    }

    /**
     * @param list<string> $targets «path/to/File.php»، یک پوشه، یا «path/to/FileTest.php::test_name»
     * @return list<string>
     */
    public function lock(array $targets): array
    {
        $lock = $this->load();
        $locked = [];

        foreach ($targets as $target) {
            $target = str_replace('\\', '/', $target);

            if (str_contains($target, '::')) {
                [$file, $name] = explode('::', $target, 2);
                $lock['tests'][$target] = $this->testHash($file, $name) ?? throw new RuntimeException("Test not found: {$target}");
                $locked[] = $target;

                continue;
            }

            $abs = $this->abs($target);
            if (is_dir($abs)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                        $relative = $this->relative($file->getPathname());
                        $lock['files'][$relative] = $this->hash((string) file_get_contents($file->getPathname()));
                        $locked[] = $relative;
                    }
                }
            } elseif (is_file($abs)) {
                $lock['files'][$target] = $this->hash((string) file_get_contents($abs));
                $locked[] = $target;
            } else {
                throw new RuntimeException("Not found: {$target}");
            }
        }

        ksort($lock['files']);
        ksort($lock['tests']);
        @mkdir(dirname($this->path()), 0777, true);
        file_put_contents($this->path(), json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return $locked;
    }

    /** @return list<Finding> */
    public function verify(): array
    {
        $lock = $this->load();
        $findings = [];

        foreach ($lock['files'] as $file => $hash) {
            $abs = $this->abs($file);
            if (! is_file($abs)) {
                $findings[] = new Finding('locked-file-deleted', Finding::HIGH, $file, null, 'A locked file was deleted');
            } elseif ($this->hash((string) file_get_contents($abs)) !== $hash) {
                $findings[] = new Finding('locked-file-changed', Finding::HIGH, $file, null, 'A locked file was changed');
            }
        }

        foreach ($lock['tests'] as $target => $hash) {
            [$file, $name] = explode('::', $target, 2);
            $now = $this->testHash($file, $name);
            if ($now === null) {
                $findings[] = new Finding('locked-test-removed', Finding::HIGH, $file, null, "Locked test {$name} was removed");
            } elseif ($now !== $hash) {
                $findings[] = new Finding('locked-test-changed', Finding::HIGH, $file, null, "Locked test {$name} was changed");
            }
        }

        return $findings;
    }

    private function testHash(string $file, string $name): ?string
    {
        $abs = $this->abs($file);
        if (! is_file($abs)) {
            return null;
        }
        foreach ($this->php->methods((string) file_get_contents($abs)) ?? [] as $key => $facts) {
            if ($key === 'pest:' . $name || str_ends_with($key, '::' . $name)) {
                return $facts->bodyHash;
            }
        }

        return null;
    }

    private function path(): string
    {
        return $this->abs(Scanner::LOCK);
    }

    private function abs(string $relative): string
    {
        return rtrim($this->root, '/\\') . '/' . ltrim($relative, '/');
    }

    private function relative(string $absolute): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root), '/') . '/';

        return substr(str_replace('\\', '/', $absolute), strlen($root));
    }

    private function hash(string $content): string
    {
        return sha1(str_replace("\r\n", "\n", $content));
    }
}
