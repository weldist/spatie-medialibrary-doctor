<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Support;

final class OrphanScan
{
    public int $owned = 0;

    public int $stray = 0;

    public ?string $error = null;

    /** @var array<string, array{files: int, newest: int, unknownAge: bool}> */
    public array $orphaned = [];

    public function __construct(
        public readonly string $disk,
        public readonly string $root,
        public readonly int $mediaCount,
    ) {}

    public function addOrphanedFile(string $directory, ?int $lastModified): void
    {
        $entry = $this->orphaned[$directory] ?? ['files' => 0, 'newest' => 0, 'unknownAge' => false];

        $entry['files']++;
        $entry['newest'] = max($entry['newest'], $lastModified ?? 0);
        $entry['unknownAge'] = $entry['unknownAge'] || $lastModified === null;

        $this->orphaned[$directory] = $entry;
    }

    public function isRecent(string $directory, int $cutoff): bool
    {
        $entry = $this->orphaned[$directory];

        return $entry['unknownAge'] || $entry['newest'] > $cutoff;
    }

    public function orphanedFiles(): int
    {
        return array_sum(array_column($this->orphaned, 'files'));
    }

    public function files(): int
    {
        return $this->owned + $this->orphanedFiles() + $this->stray;
    }

    public function orphanedPercentage(): float
    {
        $checked = $this->owned + $this->orphanedFiles();

        return $checked === 0 ? 0.0 : $this->orphanedFiles() / $checked * 100;
    }

    /**
     * @return list<string>
     */
    public function deletableDirectories(int $cutoff): array
    {
        $directories = array_map(strval(...), array_keys($this->orphaned));

        return array_values(array_filter($directories, fn (string $directory) => ! $this->isRecent($directory, $cutoff)));
    }

    public function deletionBlocker(int $cutoff, float $maxOrphanedPercentage, bool $rootIsExplicit): ?string
    {
        return match (true) {
            $this->error !== null => 'listing the disk failed',
            $this->deletableDirectories($cutoff) === [] => null,
            ! $rootIsExplicit => 'the disk root is scanned; set media-library.prefix or pass --path',
            $this->mediaCount === 0 => 'no media row points to the disk',
            $this->owned === 0 => 'no file belongs to a media row',
            $this->orphanedPercentage() > $maxOrphanedPercentage => sprintf('%.2f%% of the files are orphaned, above --max-orphaned-percent=%s', $this->orphanedPercentage(), $maxOrphanedPercentage),
            default => null,
        };
    }
}
