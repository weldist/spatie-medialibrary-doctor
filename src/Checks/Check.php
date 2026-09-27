<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Checks;

interface Check
{
    public function name(): string;

    public function run(): CheckResult;
}
