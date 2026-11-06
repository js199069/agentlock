<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Tests;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Detector\SourceDetector;
use Js199069\Agentlock\Finding;
use PHPUnit\Framework\TestCase;

final class SourceDetectorTest extends TestCase
{
    private const OLD = <<<'PHP'
    <?php

    final class Price
    {
        public function total(int $amount): int
        {
            if ($amount < 0) {
                throw new \InvalidArgumentException('negative');
            }

            return (int) round($amount * 1.1);
        }
    }
    PHP;

    private function rules(string $search, string $replace): array
    {
        $new = str_replace($search, $replace, self::OLD);
        $this->assertNotSame(self::OLD, $new);
        $findings = (new SourceDetector())->detect(new FileChange('src/Price.php', 'M', self::OLD, $new));

        return array_map(fn (Finding $f) => $f->severity . ':' . $f->rule, $findings);
    }

    public function test_removed_validation(): void
    {
        $this->assertContains('medium:validation-removed', $this->rules("throw new \\InvalidArgumentException('negative');", 'return 0;'));
    }

    public function test_special_case_for_a_fixed_input(): void
    {
        $this->assertContains('medium:literal-special-case', $this->rules(
            'return (int) round($amount * 1.1);',
            "if (\$amount === 100) {\n            return 110;\n        }\n\n        return (int) round(\$amount * 1.1);",
        ));
    }

    public function test_null_check_is_not_a_special_case(): void
    {
        $this->assertNotContains('medium:literal-special-case', $this->rules(
            'return (int) round($amount * 1.1);',
            "if (\$amount === null) {\n            return 0;\n        }\n\n        return (int) round(\$amount * 1.1);",
        ));
    }

    public function test_swallowed_exception(): void
    {
        $this->assertContains('high:swallowed-exception', $this->rules(
            'return (int) round($amount * 1.1);',
            "try {\n            return (int) round(\$amount * 1.1);\n        } catch (\\Throwable \$e) {\n            return 0;\n        }",
        ));
    }

    public function test_added_suppression(): void
    {
        $this->assertContains('medium:suppression-added', $this->rules('public function total', "/** @phpstan-ignore-next-line */\n    public function total"));
    }
}
