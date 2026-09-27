<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weldist\Spatie\MediaLibrary\Doctor\Tests\Support\TestModel;

trait CreatesMedia
{
    protected function addMedia(string $collection = 'default', string $disk = 'media', int $ageInMinutes = 120): Media
    {
        $path = sys_get_temp_dir().'/'.uniqid('media-doctor-fixture-').'.txt';
        file_put_contents($path, 'plain text');

        $media = TestModel::query()->create()
            ->addMedia($path)
            ->toMediaCollection($collection, $disk);

        Media::query()->whereKey($media->getKey())->update(['created_at' => Carbon::now()->subMinutes($ageInMinutes)]);

        return $media->refresh();
    }

    /**
     * @return list<Media>
     */
    protected function addManyMedia(int $count, string $disk = 'media'): array
    {
        return array_map(fn () => $this->addMedia(disk: $disk), range(1, $count));
    }

    protected function putFile(string $path, int $ageInMinutes = 120, string $disk = 'media'): void
    {
        Storage::disk($disk)->put($path, 'orphan');

        touch(Storage::disk($disk)->path($path), Carbon::now()->subMinutes($ageInMinutes)->getTimestamp());
    }

    protected function removeOriginal(Media $media): Media
    {
        Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());

        return $media;
    }
}
