<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaTable
{
    public static function query(): Builder
    {
        /** @var class-string<Media> $model */
        $model = config('media-library.media_model', Media::class);

        return $model::query()->withoutGlobalScopes();
    }

    /**
     * @return array<string, int>
     */
    public static function countsBy(string $column): array
    {
        return self::query()
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("{$column} as value, count(*) as aggregate")
            ->toBase()
            ->pluck('aggregate', 'value')
            ->map(fn (mixed $count) => (int) $count)
            ->all();
    }
}
