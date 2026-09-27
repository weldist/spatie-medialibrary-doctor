<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Console\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait FiltersMedia
{
    protected const string MEDIA_FILTERS = '
        {--disk=* : Limit to one or more disk names}
        {--collection=* : Limit to one or more media collection names}
        {--model=* : Limit to one or more model types}
        {--id-from= : Only include media with id >= this value}
        {--id-to= : Only include media with id <= this value}
        {--chunk=100 : Number of Media rows to process per database chunk}';

    /**
     * @return class-string<Media>
     */
    protected function mediaModel(): string
    {
        return config('media-library.media_model', Media::class);
    }

    protected function mediaQuery(): Builder
    {
        return $this->mediaModel()::query()
            ->when($this->option('disk'), fn (Builder $query, array $disks) => $query->whereIn('disk', $disks))
            ->when($this->option('collection'), fn (Builder $query, array $collections) => $query->whereIn('collection_name', $collections))
            ->when($this->option('model'), fn (Builder $query, array $models) => $query->whereIn('model_type', $models))
            ->when($this->option('id-from'), fn (Builder $query, string $id) => $query->where('id', '>=', (int) $id))
            ->when($this->option('id-to'), fn (Builder $query, string $id) => $query->where('id', '<=', (int) $id));
    }

    /**
     * @param  Closure(Media): bool  $callback  returning false stops the iteration
     */
    protected function eachMedia(Builder $query, Closure $callback): int
    {
        $total = (clone $query)->count();

        if ($total === 0) {
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($query->lazyById((int) $this->option('chunk')) as $media) {
            $bar->advance();

            if ($callback($media) === false) {
                break;
            }
        }

        $bar->finish();
        $this->newLine(2);

        return $total;
    }
}
