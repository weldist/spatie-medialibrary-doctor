<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Checks;

use Illuminate\Database\Eloquent\Builder;
use Weldist\Spatie\MediaLibrary\Doctor\Support\MediaTable;

final class DisksAreConfigured implements Check
{
    public function name(): string
    {
        return 'Disks are configured';
    }

    public function run(): CheckResult
    {
        $missing = collect(MediaTable::countsBy('disk'))->keys()
            ->concat(array_keys(MediaTable::countsBy('conversions_disk')))
            ->map(fn (int|string $disk) => (string) $disk)
            ->unique()
            ->filter(fn (string $disk) => config("filesystems.disks.{$disk}") === null)
            ->sort()
            ->values();

        if ($missing->isEmpty()) {
            return CheckResult::ok('Every disk used by media is defined in filesystems.disks.');
        }

        $list = $missing->map(fn (string $disk) => sprintf('`%s` (%d row(s))', $disk, $this->rowsUsing($disk)))->implode(', ');

        return CheckResult::failed("Not defined in filesystems.disks: {$list}. The doctor commands skip these rows.");
    }

    private function rowsUsing(string $disk): int
    {
        return MediaTable::query()
            ->where(fn (Builder $query) => $query->where('disk', $disk)->orWhere('conversions_disk', $disk))
            ->count();
    }
}
