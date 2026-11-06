<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Change;

/** یک فایل تغییرکرده: وضعیت (A, M, D, R) و محتوای قبل و بعد. */
final readonly class FileChange
{
    public function __construct(
        public string $path,
        public string $status,
        public ?string $old,
        public ?string $new,
        public ?string $oldPath = null,
    ) {}

    public function isDeleted(): bool
    {
        return $this->status === 'D';
    }
}
