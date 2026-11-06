<?php

declare(strict_types=1);

namespace Js199069\Agentlock;

final readonly class Finding
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    public function __construct(
        public string $rule,
        public string $severity,
        public string $file,
        public ?int $line,
        public string $message,
    ) {}

    public static function rank(string $severity): int
    {
        return match ($severity) {
            self::HIGH => 3,
            self::MEDIUM => 2,
            self::LOW => 1,
            default => 0,
        };
    }

    public function toArray(): array
    {
        return ['rule' => $this->rule, 'severity' => $this->severity, 'file' => $this->file, 'line' => $this->line, 'message' => $this->message];
    }
}
