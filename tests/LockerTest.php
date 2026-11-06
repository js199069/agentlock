<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Tests;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Finding;
use Js199069\Agentlock\Lock\Locker;
use Js199069\Agentlock\Report;
use Js199069\Agentlock\Scanner;
use PHPUnit\Framework\TestCase;

final class LockerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agentlock-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/tests', 0777, true);
        $this->write("\$this->assertSame(1, 1);", "\$this->assertSame(2, 2);");
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/tests/LockedTest.php');
        @unlink($this->root . '/.agentlock/lock.json');
        @rmdir($this->root . '/.agentlock');
        @rmdir($this->root . '/tests');
        @rmdir($this->root);
    }

    private function write(string $a, string $b): void
    {
        file_put_contents($this->root . '/tests/LockedTest.php', "<?php\n\nfinal class LockedTest extends TestCase\n{\n    public function test_a(): void\n    {\n        {$a}\n    }\n\n    public function test_b(): void\n    {\n        {$b}\n    }\n}\n");
    }

    private function rules(array $findings): array
    {
        return array_map(fn (Finding $f) => $f->rule, $findings);
    }

    public function test_a_locked_test_is_protected_and_its_neighbours_are_free(): void
    {
        $locker = new Locker($this->root);
        $locker->lock(['tests/LockedTest.php::test_a']);

        $this->write("\$this->assertSame(1, 1);", "\$this->assertSame(3, 3);");
        $this->assertSame([], $locker->verify());

        $this->write("\$this->assertTrue(true);", "\$this->assertSame(3, 3);");
        $this->assertSame(['locked-test-changed'], $this->rules($locker->verify()));
    }

    public function test_a_locked_file_reports_any_change_and_deletion(): void
    {
        $locker = new Locker($this->root);
        $locker->lock(['tests/LockedTest.php']);

        $this->write("\$this->assertSame(1, 1);", "\$this->assertSame(9, 9);");
        $this->assertSame(['locked-file-changed'], $this->rules($locker->verify()));

        unlink($this->root . '/tests/LockedTest.php');
        $this->assertSame(['locked-file-deleted'], $this->rules($locker->verify()));
    }

    public function test_changing_the_lock_file_itself_is_high(): void
    {
        $findings = (new Scanner())->scan([
            new FileChange('.agentlock/lock.json', 'M', '{}', '{"files": {}}'),
            new FileChange('README.md', 'M', 'a', 'b'),
        ]);

        $this->assertSame(['lock-modified'], $this->rules($findings));
        $this->assertTrue(Report::failed($findings, 'high'));
    }

    public function test_fail_on_threshold(): void
    {
        $medium = [new Finding('x', Finding::MEDIUM, 'f', null, 'm')];

        $this->assertFalse(Report::failed($medium, 'high'));
        $this->assertTrue(Report::failed($medium, 'medium'));
        $this->assertFalse(Report::failed($medium, 'none'));
    }
}
