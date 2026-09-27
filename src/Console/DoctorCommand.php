<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Console;

use Illuminate\Console\Command;
use Weldist\Spatie\MediaLibrary\Doctor\Checks\Check;
use Weldist\Spatie\MediaLibrary\Doctor\Checks\CheckStatus;
use Weldist\Spatie\MediaLibrary\Doctor\Checks\DisksAreConfigured;
use Weldist\Spatie\MediaLibrary\Doctor\Checks\PathGeneratorsAreValid;
use Weldist\Spatie\MediaLibrary\Doctor\Checks\PrefixIsSet;
use Weldist\Spatie\MediaLibrary\Doctor\Checks\RowsHaveCreationTime;
use Weldist\Spatie\MediaLibrary\Doctor\Support\MediaTable;

class DoctorCommand extends Command
{
    protected $signature = 'media-library:doctor';

    protected $description = 'Check the media-library configuration and media table without touching any disk.';

    /** @var list<class-string<Check>> */
    protected array $checks = [
        DisksAreConfigured::class,
        PathGeneratorsAreValid::class,
        PrefixIsSet::class,
        RowsHaveCreationTime::class,
    ];

    public function handle(): int
    {
        $this->reportDisks();

        $rows = [];
        $failed = false;

        foreach ($this->checks as $class) {
            /** @var Check $check */
            $check = $this->laravel->make($class);
            $result = $check->run();

            $rows[] = [$check->name(), "<{$result->status->style()}>{$result->status->value}</>", $result->message];
            $failed = $failed || $result->status === CheckStatus::Failed;
        }

        $this->table(['Check', 'Status', 'Message'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function reportDisks(): void
    {
        $media = MediaTable::countsBy('disk');
        $conversions = MediaTable::countsBy('conversions_disk');

        $disks = collect(array_keys($media))
            ->concat(array_keys($conversions))
            ->push(config('media-library.disk_name'))
            ->push(config('media-library.conversions_disk_name'))
            ->filter()
            ->map(fn (int|string $disk) => (string) $disk)
            ->unique()
            ->sort()
            ->values();

        $this->table(['Disk', 'Driver', 'Media', 'Conversions'], $disks->map(fn (string $disk): array => [
            $disk,
            config("filesystems.disks.{$disk}.driver") ?? '<error>not configured</>',
            $media[$disk] ?? 0,
            $conversions[$disk] ?? 0,
        ])->all());
    }
}
