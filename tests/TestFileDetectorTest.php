<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Tests;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Detector\TestFileDetector;
use Js199069\Agentlock\Finding;
use PHPUnit\Framework\TestCase;

final class TestFileDetectorTest extends TestCase
{
    private const OLD = <<<'PHP'
    <?php

    namespace Tests;

    use PHPUnit\Framework\TestCase;

    final class PriceTest extends TestCase
    {
        public function test_total(): void
        {
            $this->assertSame(110, (new Price())->total(100));
            $this->assertSame(0, (new Price())->total(0));
        }

        public function test_negative_throws(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            (new Price())->total(-1);
        }
    }
    PHP;

    /** @return list<string> «severity:rule» */
    private function rules(?string $new, string $status = 'M', string $old = self::OLD, string $path = 'tests/PriceTest.php'): array
    {
        $findings = (new TestFileDetector())->detect(new FileChange($path, $status, $old, $new));

        return array_map(fn (Finding $f) => $f->severity . ':' . $f->rule, $findings);
    }

    private function variant(array $search, array $replace): string
    {
        $new = str_replace($search, $replace, self::OLD);
        $this->assertNotSame(self::OLD, $new, 'the variant must change the file');

        return $new;
    }

    public function test_removed_test_is_high(): void
    {
        $new = $this->variant(['public function test_negative_throws(): void'], ['private function helper(): void']);

        $this->assertContains('high:test-removed', $this->rules($new));
    }

    public function test_skipped_test_is_high(): void
    {
        $new = $this->variant(['$this->assertSame(110,'], ["\$this->markTestSkipped('flaky');\n        \$this->assertSame(110,"]);

        $this->assertContains('high:test-skipped', $this->rules($new));
    }

    public function test_emptied_test_is_high(): void
    {
        $new = $this->variant(
            ['$this->assertSame(110, (new Price())->total(100));', '$this->assertSame(0, (new Price())->total(0));'],
            ['(new Price())->total(100);', '(new Price())->total(0);'],
        );

        $this->assertContains('high:test-emptied', $this->rules($new));
    }

    public function test_fewer_assertions_is_medium(): void
    {
        $new = $this->variant(['$this->assertSame(0, (new Price())->total(0));'], ['']);

        $this->assertContains('medium:assertions-reduced', $this->rules($new));
    }

    public function test_assertion_that_cannot_fail_is_high(): void
    {
        $new = $this->variant(['$this->assertSame(110, (new Price())->total(100));'], ['$this->assertTrue(true);']);

        $this->assertContains('high:tautology', $this->rules($new));
    }

    public function test_changed_expected_value_is_medium(): void
    {
        $new = $this->variant(['assertSame(110,'], ['assertSame(120,']);

        $this->assertContains('medium:expectation-changed', $this->rules($new));
    }

    public function test_rename_is_low_not_a_removal(): void
    {
        $rules = $this->rules($this->variant(['function test_total()'], ['function test_total_with_tax()']));

        $this->assertContains('low:test-renamed', $rules);
        $this->assertNotContains('high:test-removed', $rules);
    }

    public function test_deleted_test_file_is_high(): void
    {
        $this->assertSame(['high:test-file-deleted'], $this->rules(null, 'D'));
    }

    public function test_untouched_file_has_no_findings(): void
    {
        $this->assertSame([], $this->rules(self::OLD));
    }

    public function test_removed_pest_test_is_high(): void
    {
        $old = "<?php\n\ntest('adds tax', function () {\n    expect(total(100))->toBe(110);\n});\n\nit('rejects negatives', function () {\n    expect(fn () => total(-1))->toThrow(InvalidArgumentException::class);\n});\n";
        $new = "<?php\n\ntest('adds tax', function () {\n    expect(total(100))->toBe(110);\n});\n";

        $this->assertContains('high:test-removed', $this->rules($new, 'M', $old, 'tests/PriceTest.php'));
    }
}
