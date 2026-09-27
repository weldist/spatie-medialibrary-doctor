<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\Doctor\Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ScopedMedia extends Media
{
    protected $table = 'media';

    protected static function booted(): void
    {
        static::addGlobalScope('hidden', fn (Builder $query) => $query->whereRaw('1 = 0'));
    }
}
