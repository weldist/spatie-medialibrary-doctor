<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Checks;

final class PrefixIsSet implements Check
{
    public function name(): string
    {
        return 'Media prefix is set';
    }

    public function run(): CheckResult
    {
        $prefix = trim((string) config('media-library.prefix', ''), '/');

        if ($prefix === '') {
            return CheckResult::warning('media-library.prefix is empty; media-library:doctor:orphaned-files only deletes with --path.');
        }

        return CheckResult::ok("Media is stored under `{$prefix}`.");
    }
}
