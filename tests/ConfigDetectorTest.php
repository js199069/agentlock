<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Tests;

use Js199069\Agentlock\Change\FileChange;
use Js199069\Agentlock\Detector\ConfigDetector;
use Js199069\Agentlock\Finding;
use PHPUnit\Framework\TestCase;

final class ConfigDetectorTest extends TestCase
{
    private function rules(string $path, string $old, string $new): array
    {
        $findings = (new ConfigDetector())->detect(new FileChange($path, 'M', $old, $new));

        return array_map(fn (Finding $f) => $f->severity . ':' . $f->rule, $findings);
    }

    public function test_supported_files(): void
    {
        $detector = new ConfigDetector();

        $this->assertTrue($detector->supports('phpunit.xml.dist'));
        $this->assertTrue($detector->supports('.github/workflows/tests.yml'));
        $this->assertTrue($detector->supports('phpstan-baseline.neon'));
        $this->assertFalse($detector->supports('README.md'));
    }

    public function test_test_excluded_from_phpunit_suite(): void
    {
        $old = '<phpunit><testsuites><testsuite name="unit"><directory>tests</directory></testsuite></testsuites></phpunit>';
        $new = '<phpunit><testsuites><testsuite name="unit"><directory>tests</directory><exclude>tests/PaymentTest.php</exclude></testsuite></testsuites></phpunit>';

        $this->assertContains('high:suite-excluded', $this->rules('phpunit.xml', $old, $new));
    }

    public function test_phpunit_strictness_lowered(): void
    {
        $this->assertContains('medium:strictness-lowered', $this->rules('phpunit.xml.dist', '<phpunit failOnWarning="true"/>', '<phpunit failOnWarning="false"/>'));
    }

    public function test_phpstan_level_lowered_and_errors_ignored(): void
    {
        $old = "parameters:\n    level: 8\n    paths:\n        - src\n";
        $new = "parameters:\n    level: 5\n    paths:\n        - src\n    ignoreErrors:\n        - '#Call to an undefined method#'\n";

        $rules = $this->rules('phpstan.neon', $old, $new);
        $this->assertContains('high:analysis-level-lowered', $rules);
        $this->assertContains('medium:ignore-errors-added', $rules);
    }

    public function test_composer_script_ignores_failure(): void
    {
        $this->assertContains('high:script-ignores-failure', $this->rules('composer.json', '{"scripts": {"test": "phpunit"}}', '{"scripts": {"test": "phpunit || true"}}'));
    }

    public function test_ci_step_ignores_failure(): void
    {
        $old = "steps:\n  - run: vendor/bin/phpunit\n";
        $new = "steps:\n  - run: vendor/bin/phpunit\n    continue-on-error: true\n";

        $this->assertContains('high:ci-failure-ignored', $this->rules('.github/workflows/ci.yml', $old, $new));
    }

    public function test_infection_gate_lowered(): void
    {
        $this->assertContains('high:quality-gate-lowered', $this->rules('infection.json5', '{"minMsi": 80}', '{"minMsi": 50}'));
    }
}
