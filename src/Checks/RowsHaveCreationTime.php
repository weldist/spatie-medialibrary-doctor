<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Checks;

use Weldist\Spatie\MediaLibrary\Doctor\Support\MediaTable;

final class RowsHaveCreationTime implements Check
{
    public function name(): string
    {
        return 'Media rows have created_at';
    }

    public function run(): CheckResult
    {
        $count = MediaTable::query()->whereNull('created_at')->count();

        if ($count === 0) {
            return CheckResult::ok('Every media row has created_at.');
        }

        return CheckResult::warning("{$count} media row(s) without created_at; media-library:doctor:missing-originals only inspects them with --min-age=0.");
    }
}
