<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Php;

/** واقعیت‌های قابل مقایسه درباره‌ی یک متد یا یک تست Pest. */
final readonly class MethodFacts
{
    /**
     * @param list<int> $tautologyLines
     * @param list<string> $expectedLiterals
     */
    public function __construct(
        public string $key,
        public int $line,
        public bool $isTest,
        public string $bodyHash,
        public int $assertions,
        public int $skips,
        public array $tautologyLines,
        public array $expectedLiterals,
        public int $throws,
        public int $swallowedCatches,
        public int $literalSpecialCases,
        public int $unguardedTryInTest,
    ) {}
}
