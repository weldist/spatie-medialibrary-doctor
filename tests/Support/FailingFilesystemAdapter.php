<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Support;

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToListContents;

class FailingFilesystemAdapter extends LocalFilesystemAdapter
{
    public function fileExists(string $location): bool
    {
        throw UnableToCheckFileExistence::forLocation($location);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        throw UnableToListContents::atLocation($path, $deep, new \RuntimeException('Connection refused'));
    }
}
