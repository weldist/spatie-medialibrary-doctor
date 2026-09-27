<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class ShardedPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return "library/shard/{$media->getKey()}/";
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media).'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media).'responsive-images/';
    }
}
