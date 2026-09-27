<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Support;

final class DiskScan
{
    public int $present = 0;

    public int $skipped = 0;

    /** @var list<int|string> */
    public array $missing = [];

    /** @var list<array{int|string, string}> */
    public array $errors = [];

    public function __construct(
        public readonly string $disk,
        public readonly bool $configured,
    ) {}

    public function inspected(): int
    {
        return $this->present + count($this->missing) + count($this->errors) + $this->skipped;
    }

    public function missingPercentage(): float
    {
        $checked = $this->present + count($this->missing);

        return $checked === 0 ? 0.0 : count($this->missing) / $checked * 100;
    }

    public function deletionBlocker(float $maxMissingPercentage): ?string
    {
        return match (true) {
            ! $this->configured => 'disk is not configured',
            $this->errors !== [] => 'some existence checks failed',
            $this->missing === [] => null,
            $this->present === 0 => 'no file could be found on the disk',
            $this->missingPercentage() > $maxMissingPercentage => sprintf('%.2f%% of the files are missing, above --max-missing-percent=%s', $this->missingPercentage(), $maxMissingPercentage),
            default => null,
        };
    }
}
