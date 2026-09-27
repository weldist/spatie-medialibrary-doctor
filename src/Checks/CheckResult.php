<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Checks;

final readonly class CheckResult
{
    private function __construct(
        public CheckStatus $status,
        public string $message,
    ) {}

    public static function ok(string $message): self
    {
        return new self(CheckStatus::Ok, $message);
    }

    public static function warning(string $message): self
    {
        return new self(CheckStatus::Warning, $message);
    }

    public static function failed(string $message): self
    {
        return new self(CheckStatus::Failed, $message);
    }
}
