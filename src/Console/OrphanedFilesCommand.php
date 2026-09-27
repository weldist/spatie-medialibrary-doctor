<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use League\Flysystem\StorageAttributes;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;
use Weldist\Spatie\MediaLibrary\Doctor\Console\Concerns\FiltersMedia;
use Weldist\Spatie\MediaLibrary\Doctor\Support\OrphanScan;

class OrphanedFilesCommand extends Command
{
    use FiltersMedia;

    protected $signature = 'media-library:doctor:orphaned-files
        {--delete : Delete the directories that belong to no media row}
        {--force : Delete without asking for confirmation}
        {--path= : Directory to scan, relative to the disk root (default: media-library.prefix, "/" for the disk root)}
        {--disk=* : Limit to one or more disk names (default: every disk used by media)}
        {--min-age=60 : Only delete directories whose newest file was modified at least this many minutes ago}
        {--max-orphaned-percent=10 : Refuse to delete on a disk where more than this percentage of the files is orphaned}
        {--chunk=100 : Number of Media rows to process per database chunk}';

    protected $description = 'Report, and optionally delete, directories on media disks that belong to no media row.';

    private Factory $filesystem;

    public function handle(Factory $filesystem): int
    {
        if (! $this->hasValidOptions()) {
            return self::FAILURE;
        }

        $this->filesystem = $filesystem;

        $root = $this->root();
        $scans = [];

        foreach ($this->diskNames() as $disk) {
            if (config("filesystems.disks.{$disk}") === null) {
                $this->warn("Skipping disk `{$disk}`: disk is not configured.");

                continue;
            }

            $scans[] = $this->scan($disk, $root);
        }

        if ($scans === []) {
            $this->info('No disk to scan.');

            return self::SUCCESS;
        }

        $this->report($scans);

        $failed = collect($scans)->contains(fn (OrphanScan $scan) => $scan->error !== null);

        if (! $this->option('delete')) {
            return $failed ? self::FAILURE : self::SUCCESS;
        }

        return $this->deleteOrphaned($scans) && ! $failed ? self::SUCCESS : self::FAILURE;
    }

    private function hasValidOptions(): bool
    {
        $invalid = collect([
            'min-age' => filter_var($this->option('min-age'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]),
            'max-orphaned-percent' => filter_var($this->option('max-orphaned-percent'), FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 100]]),
        ])->filter(fn (mixed $value) => $value === false)->keys();

        foreach ($invalid as $option) {
            $this->error("Invalid value for --{$option}.");
        }

        return $invalid->isEmpty();
    }

    private function root(): string
    {
        return trim((string) ($this->option('path') ?? config('media-library.prefix', '')), '/');
    }

    private function rootIsExplicit(): bool
    {
        return $this->option('path') !== null || $this->root() !== '';
    }

    /**
     * @return list<string>
     */
    private function diskNames(): array
    {
        if ($this->option('disk') !== []) {
            return array_values(array_unique($this->option('disk')));
        }

        return $this->mediaQuery()->distinct()->pluck('disk')
            ->concat($this->mediaQuery()->whereNotNull('conversions_disk')->distinct()->pluck('conversions_disk'))
            ->push(config('media-library.disk_name'))
            ->push(config('media-library.conversions_disk_name'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function mediaQuery(): Builder
    {
        return $this->mediaModel()::query()->withoutGlobalScopes();
    }

    private function cutoff(): int
    {
        return Carbon::now()->subMinutes((int) $this->option('min-age'))->getTimestamp();
    }

    private function scan(string $disk, string $root): OrphanScan
    {
        [$owned, $ancestors, $mediaCount] = $this->mediaDirectories($disk);

        $scan = new OrphanScan($disk, $root, $mediaCount);

        $adapter = $this->filesystem->disk($disk);

        if (! $adapter instanceof FilesystemAdapter) {
            $scan->error = 'the disk does not expose a Flysystem driver';

            return $scan;
        }

        try {
            foreach ($adapter->getDriver()->listContents($root, true) as $item) {
                if ($item->isFile()) {
                    $this->classify($scan, $item, $owned, $ancestors);
                }
            }
        } catch (Throwable $e) {
            $scan->error = $e->getMessage();
        }

        return $scan;
    }

    /**
     * @param  array<string, true>  $owned
     * @param  array<string, true>  $ancestors
     */
    private function classify(OrphanScan $scan, StorageAttributes $file, array $owned, array $ancestors): void
    {
        $directory = dirname($file->path());

        if ($directory === '.' || $directory === $scan->root) {
            $scan->stray++;

            return;
        }

        $relative = $scan->root === '' ? $directory : substr($directory, strlen($scan->root) + 1);
        $candidate = $scan->root;

        foreach (explode('/', $relative) as $segment) {
            $candidate = $candidate === '' ? $segment : "{$candidate}/{$segment}";

            if (isset($owned[$candidate])) {
                $scan->owned++;

                return;
            }

            if (! isset($ancestors[$candidate])) {
                $scan->addOrphanedFile($candidate, $file->lastModified());

                return;
            }
        }

        $scan->stray++;
    }

    /**
     * @return array{array<string, true>, array<string, true>, int}
     */
    private function mediaDirectories(string $disk): array
    {
        $owned = [];
        $ancestors = [];
        $count = 0;

        $query = $this->mediaQuery()->where(fn (Builder $query) => $query
            ->where('disk', $disk)
            ->orWhere('conversions_disk', $disk));

        foreach ($query->lazyById((int) $this->option('chunk')) as $media) {
            $count++;

            foreach ($this->directoriesOf($media, $disk) as $directory) {
                $owned[$directory] = true;

                for ($parent = dirname($directory); $parent !== '.'; $parent = dirname($parent)) {
                    $ancestors[$parent] = true;
                }
            }
        }

        return [$owned, $ancestors, $count];
    }

    /**
     * @return list<string>
     */
    private function directoriesOf(Media $media, string $disk): array
    {
        $generator = PathGeneratorFactory::create($media);
        $conversionsDisk = $media->conversions_disk ?: $media->disk;

        $paths = [
            ...($media->disk === $disk ? [$generator->getPath($media)] : []),
            ...($conversionsDisk === $disk ? [$generator->getPathForConversions($media), $generator->getPathForResponsiveImages($media)] : []),
        ];

        return array_values(array_filter(array_map(fn (string $path) => trim($path, '/'), $paths), fn (string $path) => $path !== ''));
    }

    /**
     * @param  list<OrphanScan>  $scans
     */
    private function report(array $scans): void
    {
        $cutoff = $this->cutoff();
        $maxOrphanedPercentage = (float) $this->option('max-orphaned-percent');

        $this->table(
            ['Disk', 'Path', 'Files', 'Owned', 'Orphaned', 'Directories', 'Recent', 'Stray', 'Deletion'],
            array_map(fn (OrphanScan $scan): array => [
                $scan->disk,
                $scan->root === '' ? '/' : $scan->root,
                $scan->files(),
                $scan->owned,
                $scan->orphanedFiles(),
                count($scan->orphaned),
                count($scan->orphaned) - count($scan->deletableDirectories($cutoff)),
                $scan->stray,
                $scan->deletionBlocker($cutoff, $maxOrphanedPercentage, $this->rootIsExplicit())
                    ?? ($scan->deletableDirectories($cutoff) === [] ? '-' : 'allowed'),
            ], $scans),
        );

        foreach ($scans as $scan) {
            if ($scan->error !== null) {
                $this->error("Listing disk `{$scan->disk}` failed: {$scan->error}");
            }

            if ($this->output->isVerbose()) {
                foreach ($scan->orphaned as $directory => $entry) {
                    $this->line("Orphaned directory `{$directory}` on disk `{$scan->disk}` ({$entry['files']} file(s)).");
                }
            }
        }
    }

    /**
     * @param  list<OrphanScan>  $scans
     */
    private function deleteOrphaned(array $scans): bool
    {
        $cutoff = $this->cutoff();
        $maxOrphanedPercentage = (float) $this->option('max-orphaned-percent');
        $deletable = [];
        $blocked = false;

        foreach ($scans as $scan) {
            $blocker = $scan->deletionBlocker($cutoff, $maxOrphanedPercentage, $this->rootIsExplicit());

            if ($blocker !== null) {
                $this->warn("Not deleting on disk `{$scan->disk}`: {$blocker}.");
                $blocked = true;

                continue;
            }

            if ($scan->deletableDirectories($cutoff) !== []) {
                $deletable[$scan->disk] = $scan->deletableDirectories($cutoff);
            }
        }

        if ($deletable === []) {
            $this->info('No directories to delete.');

            return ! $blocked;
        }

        $count = array_sum(array_map('count', $deletable));

        if (! $this->option('force') && ! $this->confirm("Delete {$count} orphaned directory(ies)?")) {
            $this->info('Nothing was deleted.');

            return false;
        }

        $stats = ['deleted' => 0, 'claimed' => 0, 'failed' => 0];

        foreach ($deletable as $disk => $directories) {
            [$owned, $ancestors] = $this->mediaDirectories($disk);

            foreach ($directories as $directory) {
                if ($this->isClaimed($directory, $owned, $ancestors)) {
                    $stats['claimed']++;

                    continue;
                }

                try {
                    $deleted = $this->filesystem->disk($disk)->deleteDirectory($directory);
                } catch (Throwable $e) {
                    $this->error("Directory `{$directory}` on disk `{$disk}`: {$e->getMessage()}");
                    $deleted = false;
                }

                $stats[$deleted ? 'deleted' : 'failed']++;
            }
        }

        $this->table(['Status', 'Count'], [
            ['Deleted', $stats['deleted']],
            ['Claimed by a media row', $stats['claimed']],
            ['Failed', $stats['failed']],
        ]);

        return ! $blocked && $stats['failed'] === 0;
    }

    /**
     * @param  array<string, true>  $owned
     * @param  array<string, true>  $ancestors
     */
    private function isClaimed(string $directory, array $owned, array $ancestors): bool
    {
        if (isset($ancestors[$directory])) {
            return true;
        }

        for ($path = $directory; $path !== '.'; $path = dirname($path)) {
            if (isset($owned[$path])) {
                return true;
            }
        }

        return false;
    }
}
