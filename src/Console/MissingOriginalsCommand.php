<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Weldist\Spatie\MediaLibrary\Doctor\Console\Concerns\FiltersMedia;
use Weldist\Spatie\MediaLibrary\Doctor\Support\DiskScan;

class MissingOriginalsCommand extends Command
{
    use FiltersMedia;

    protected $signature = 'media-library:doctor:missing-originals
        {--delete : Delete the media rows whose original file is missing}
        {--min-age=60 : Only inspect media created at least this many minutes ago, 0 inspects every row}
        {--max-missing-percent=10 : Refuse to delete on a disk where more than this percentage of the checked files is missing}
        {--max-errors=10 : Abort the scan once this many existence checks have failed}
        {--force : Delete without asking for confirmation}'.self::MEDIA_FILTERS;

    protected $description = 'Report, and optionally delete, media rows whose original file no longer exists on their disk.';

    private Factory $filesystem;

    /** @var array<string, DiskScan> */
    private array $scans = [];

    public function handle(Factory $filesystem): int
    {
        if (! $this->hasValidOptions()) {
            return self::FAILURE;
        }

        $this->filesystem = $filesystem;
        $this->scans = [];

        $completed = $this->scan();

        if ($this->scans === []) {
            $this->info('No matching media rows found.');

            return self::SUCCESS;
        }

        $this->report();

        if (! $completed) {
            $this->error("Scan aborted after {$this->option('max-errors')} failed existence check(s); nothing was deleted.");

            return self::FAILURE;
        }

        if (! $this->option('delete')) {
            return $this->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        return $this->deleteMissing();
    }

    private function hasValidOptions(): bool
    {
        $invalid = collect([
            'min-age' => filter_var($this->option('min-age'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]),
            'max-errors' => filter_var($this->option('max-errors'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            'max-missing-percent' => filter_var($this->option('max-missing-percent'), FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 100]]),
        ])->filter(fn (mixed $value) => $value === false)->keys();

        foreach ($invalid as $option) {
            $this->error("Invalid value for --{$option}.");
        }

        return $invalid->isEmpty();
    }

    private function scan(): bool
    {
        $maxErrors = (int) $this->option('max-errors');
        $errors = 0;
        $completed = true;

        $this->eachMedia($this->inspectedQuery(), function (Media $media) use ($maxErrors, &$errors, &$completed): bool {
            $scan = $this->scans[$media->disk] ??= new DiskScan($media->disk, config("filesystems.disks.{$media->disk}") !== null);

            if (! $scan->configured) {
                $scan->skipped++;

                return true;
            }

            try {
                if ($this->originalExists($media)) {
                    $scan->present++;
                } else {
                    $scan->missing[] = $media->getKey();
                }
            } catch (Throwable $e) {
                $scan->errors[] = [$media->getKey(), $e->getMessage()];

                if (++$errors >= $maxErrors) {
                    return $completed = false;
                }
            }

            return true;
        });

        return $completed;
    }

    private function inspectedQuery(): Builder
    {
        $minutes = (int) $this->option('min-age');

        return $this->mediaQuery()
            ->when($minutes > 0, fn (Builder $query) => $query->where('created_at', '<=', Carbon::now()->subMinutes($minutes)));
    }

    private function originalExists(Media $media): bool
    {
        return $this->filesystem->disk($media->disk)->fileExists($media->getPathRelativeToRoot());
    }

    private function report(): void
    {
        $maxMissingPercentage = (float) $this->option('max-missing-percent');

        $this->table(
            ['Disk', 'Inspected', 'Present', 'Missing', 'Errors', 'Skipped', 'Deletion'],
            array_map(fn (DiskScan $scan): array => [
                $scan->disk,
                $scan->inspected(),
                $scan->present,
                count($scan->missing),
                count($scan->errors),
                $scan->skipped,
                $scan->deletionBlocker($maxMissingPercentage) ?? ($scan->missing === [] ? '-' : 'allowed'),
            ], array_values($this->scans)),
        );

        foreach ($this->scans as $scan) {
            foreach ($scan->errors as [$id, $message]) {
                $this->error("Media #{$id} on disk `{$scan->disk}`: {$message}");
            }

            if ($this->output->isVerbose()) {
                foreach ($scan->missing as $id) {
                    $this->line("Media #{$id} on disk `{$scan->disk}` has no original file.");
                }
            }
        }
    }

    private function hasErrors(): bool
    {
        return collect($this->scans)->contains(fn (DiskScan $scan) => $scan->errors !== []);
    }

    private function deleteMissing(): int
    {
        $maxMissingPercentage = (float) $this->option('max-missing-percent');
        $deletable = [];
        $blocked = false;

        foreach ($this->scans as $scan) {
            if ($scan->missing === []) {
                continue;
            }

            $blocker = $scan->deletionBlocker($maxMissingPercentage);

            if ($blocker !== null) {
                $this->warn("Not deleting on disk `{$scan->disk}`: {$blocker}.");
                $blocked = true;

                continue;
            }

            array_push($deletable, ...$scan->missing);
        }

        if ($deletable === []) {
            $this->info('No media rows to delete.');

            return $blocked || $this->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        $count = count($deletable);

        if (! $this->option('force') && ! $this->confirm("Delete {$count} media row(s) whose original file is missing?")) {
            $this->info('Nothing was deleted.');

            return self::FAILURE;
        }

        $stats = ['deleted' => 0, 'reappeared' => 0, 'gone' => 0, 'failed' => 0];

        foreach ($deletable as $id) {
            $stats[$this->deleteIfStillMissing($id)]++;
        }

        $this->table(['Status', 'Count'], [
            ['Deleted', $stats['deleted']],
            ['File reappeared', $stats['reappeared']],
            ['Already removed', $stats['gone']],
            ['Failed', $stats['failed']],
        ]);

        return $blocked || $stats['failed'] > 0 || $this->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return 'deleted'|'reappeared'|'gone'|'failed'
     */
    private function deleteIfStillMissing(int|string $id): string
    {
        $media = $this->mediaModel()::query()->find($id);

        if ($media === null) {
            return 'gone';
        }

        try {
            if ($this->originalExists($media)) {
                return 'reappeared';
            }

            $media->delete();
        } catch (Throwable $e) {
            $this->error("Media #{$id}: {$e->getMessage()}");

            return 'failed';
        }

        return 'deleted';
    }
}
