<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Support;

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;

class FailingFilesystemAdapter extends LocalFilesystemAdapter
{
    public function fileExists(string $location): bool
    {
        throw UnableToCheckFileExistence::forLocation($location);
    }
}
