<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Tests;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Detector\TestFileDetector;
use Js199069\Agentlock\Finding;
use PHPUnit\Framework\TestCase;

final class WeakenedAssertionsTest extends TestCase
{
    private function rules(string $old, string $new): array
    {
        $findings = (new TestFileDetector())->detect(new FileChange('tests/CostTest.php', 'M', $old, $new));

        return array_map(fn (Finding $f) => $f->severity . ':' . $f->rule, $findings);
    }

    public function test_exact_assertion_replaced_by_a_weaker_one(): void
    {
        // همان تقلبی که روی پروژه‌ی آزمایشی امتحان شد: مقدار دقیق ← «فقط مثبت است»
        $old = "<?php\nfinal class CostTest extends TestCase\n{\n    public function test_cost(): void\n    {\n        \$this->assertSame(\$expected, \$calc->cost(1, 'tehran'));\n    }\n}\n";
        $new = str_replace('assertSame($expected,', 'assertGreaterThan(0,', $old);

        $this->assertContains('medium:assertions-weakened', $this->rules($old, $new));
    }

    public function test_pest_exact_expectation_replaced(): void
    {
        $old = "<?php\n\ntest('cost', function () {\n    expect(cost(1))->toBe(30000);\n});\n";
        $new = "<?php\n\ntest('cost', function () {\n    expect(cost(1))->toBeGreaterThan(0);\n});\n";

        $this->assertContains('medium:assertions-weakened', $this->rules($old, $new));
    }
}
